<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjector;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\PollingStrategy;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use JsonException;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseOperationProcessor implements OperationProcessor
{
    public function __construct(
        private KernelDatabase $database,
        private OperationLeaseManager $leases,
        private DatabaseStoredOperationLoader $loader,
        private DatabaseEffectBoundaryFactory $boundaries,
        private DatabaseOperationFinalizer $finalizer,
        private DefinitionRegistry $definitions,
        private Container $container,
        private ?DatabaseAuthoritativePollLeaseManager $pollLeases = null,
        private ?DatabaseAuthoritativePollFinalizer $pollFinalizer = null,
        private ?AuthoritativeDefinitionRegistry $authoritativeDefinitions = null,
    ) {}

    public function process(OperationId $operationId): void
    {
        $this->assertNoRuntimeTransaction();
        $workerIdentity = $this->workerIdentity();
        $claim = $this->pollLeases?->claim($operationId, $workerIdentity)
            ?? $this->leases->claim($operationId, $workerIdentity);

        if ($claim === null) {
            return;
        }

        $loaded = $this->loader->load($claim);

        match ($claim->purpose) {
            LeasePurpose::Execute => $this->execute($loaded),
            LeasePurpose::Poll => $this->poll($loaded),
            LeasePurpose::Reconcile => $this->reconcile($loaded),
        };
    }

    private function execute(LoadedOperation $loaded): void
    {
        try {
            $handler = $this->resolve($loaded->definition->handler, OperationHandler::class);
            $boundary = $this->boundaries->make($loaded->lease);
            $execution = new StoredOperationExecution($loaded->view, $boundary);
            $outcome = $this->providerCall(fn (): ExecutionOutcome => $handler->execute($execution));

            if ($loaded->definition->maximumRemoteWrites === 1 && ! $boundary->wasOpened()) {
                $this->finalizer->runtimeFailure($loaded, 'required_effect_boundary_not_opened');

                return;
            }

            if ($outcome->requiresPolling) {
                $authoritative = $this->authoritativeDefinition($loaded);

                if (! $authoritative instanceof AuthoritativeOperationDefinition
                    || $authoritative->polling === null
                    || $authoritative->pollingStrategy === null) {
                    $this->finalizer->runtimeFailure($loaded, 'execution_polling_contract_unavailable');

                    return;
                }

                $this->finalizer->continuePolling($loaded, $authoritative);

                return;
            }

            $codec = $this->resolve($loaded->definition->resultCodec, OperationResultCodec::class);
            $projector = $this->resolve($loaded->definition->outcomeProjector, OutcomeProjector::class);
            $encodedResult = $this->encode($loaded, $codec, $outcome);
            $this->finalizer->succeed($loaded, $outcome, $encodedResult, $projector);
        } catch (Throwable $failure) {
            if ($failure instanceof OperationPersistenceFailed) {
                throw $failure;
            }

            $this->classifyExecutionFailure($loaded, $failure);
        }
    }

    private function classifyExecutionFailure(LoadedOperation $loaded, Throwable $failure): void
    {
        try {
            $authoritative = $this->authoritativeDefinition($loaded);

            if ($authoritative instanceof AuthoritativeOperationDefinition) {
                $classifier = $this->resolveAuthoritative(
                    $authoritative,
                    $authoritative->failureClassifier,
                    AuthoritativeFailureClassifier::class,
                );
                $policy = $this->resolveAuthoritative(
                    $authoritative,
                    $authoritative->retryPolicy,
                    AuthoritativeRetryPolicy::class,
                );
                $classification = $this->providerCall(
                    fn (): ClassifiedFailure => $classifier->classify($loaded->view, $failure),
                );
                $instruction = $this->providerCall(
                    fn (): RetryInstruction => $policy->decide($loaded->view, $classification),
                );

                $this->finalizer->failExecution(
                    $loaded,
                    new FailureClassification($classification->disposition, $classification->safeFailure),
                    $instruction,
                    $classification->reconciliationTrigger,
                );

                return;
            }

            $classifier = $this->resolve($loaded->definition->failureClassifier, FailureClassifier::class);
            $policy = $this->resolve($loaded->definition->retryPolicy, RetryPolicy::class);
            $classification = $this->providerCall(
                fn (): FailureClassification => $classifier->classify($loaded->view, $failure),
            );
            $instruction = $this->providerCall(
                fn (): RetryInstruction => $policy->decide($loaded->view, $classification),
            );

            $this->finalizer->failExecution($loaded, $classification, $instruction);
        } catch (Throwable $classificationFailure) {
            if ($classificationFailure instanceof OperationPersistenceFailed) {
                throw $classificationFailure;
            }

            $this->finalizer->runtimeFailure($loaded, 'failure_classification_contract');
        }
    }

    private function reconcile(LoadedOperation $loaded): void
    {
        try {
            $authoritative = $this->authoritativeDefinition($loaded);

            if ($authoritative instanceof AuthoritativeOperationDefinition) {
                $strategy = $this->resolveAuthoritative(
                    $authoritative,
                    $authoritative->reconciliationStrategy,
                    AuthoritativeReconciliationStrategy::class,
                );
                $context = $this->authoritativeReconciliationContext($loaded);
                $authoritativeOutcome = $this->providerCall(
                    fn (): AuthoritativeReconciliationOutcome => $strategy->reconcile($context),
                );
                [$outcome, $encodedResult, $projector, $projection] = $this->authoritativeReconciliationResult(
                    $loaded,
                    $authoritative,
                    $authoritativeOutcome,
                );
                [$observationProjector, $observationProjection] = $this->authoritativeObservationProjection(
                    $loaded,
                    $authoritative,
                    $authoritativeOutcome,
                );
                $this->finalizer->reconcileAuthoritative(
                    $loaded,
                    $authoritative,
                    $authoritativeOutcome,
                    $outcome,
                    $encodedResult,
                    $projector,
                    $projection,
                    $observationProjector,
                    $observationProjection,
                );

                return;
            }

            $strategy = $this->resolve($loaded->definition->reconciliationStrategy, ReconciliationStrategy::class);
            $context = new StoredReconciliationContext($loaded->view, $loaded->observationNumber);
            $reconciliation = $this->providerCall(
                fn (): ReconciliationOutcome => $strategy->reconcile($context),
            );

            [$outcome, $encodedResult, $projector] = $this->reconciliationResult($loaded, $reconciliation);
            $this->finalizer->reconcile(
                $loaded,
                $reconciliation,
                $outcome,
                $encodedResult,
                $projector,
            );
        } catch (Throwable $failure) {
            if ($failure instanceof OperationPersistenceFailed) {
                throw $failure;
            }

            $this->finalizer->runtimeFailure($loaded, 'reconciliation_runtime_contract');
        }
    }

    private function authoritativeReconciliationContext(
        LoadedOperation $loaded,
    ): StoredAuthoritativeReconciliationContext {
        $claim = $loaded->lease->claim();
        $connection = $this->database->connection();
        $runtime = $connection->table('integration_operations as operation')
            ->join(
                'integration_operation_attempts as attempt',
                'attempt.id',
                '=',
                'operation.active_attempt_id',
            )
            ->where('operation.id', $claim->operationId->value)
            ->where('operation.provider', $claim->scope->provider->value)
            ->where('operation.connection_key', $claim->scope->connection->value)
            ->first([
                'operation.request_started_at',
                'attempt.started_at as observation_started_at',
            ]);

        if (! $runtime instanceof stdClass
            || ! is_string($runtime->request_started_at ?? null)
            || ! is_string($runtime->observation_started_at ?? null)) {
            throw new OperationPersistenceFailed;
        }

        $historyRows = $connection->table('integration_operation_attempts')
            ->where('operation_id', $claim->operationId->value)
            ->where('mode', LeasePurpose::Reconcile->value)
            ->whereNotNull('finished_at')
            ->orderBy('attempt_no')
            ->limit(100)
            ->get(['attempt_no', 'safe_outcome_category', 'safe_metadata', 'finished_at']);
        $observations = [];

        foreach ($historyRows as $index => $row) {
            if (! is_int($row->attempt_no ?? null)
                || ! is_string($row->safe_outcome_category ?? null)
                || ! is_string($row->safe_metadata ?? null)
                || ! is_string($row->finished_at ?? null)) {
                throw new OperationPersistenceFailed;
            }

            $result = ReconciliationResult::tryFrom($row->safe_outcome_category);
            $metadata = $this->safeMetadata($row->safe_metadata);
            $evidenceCode = $metadata['evidence_code'] ?? null;

            if (! $result instanceof ReconciliationResult || ! is_string($evidenceCode)) {
                throw new OperationPersistenceFailed;
            }

            $observations[] = new ReconciliationObservation(
                $index + 1,
                $result,
                $evidenceCode,
                $this->utc($row->finished_at),
            );
        }

        $trigger = ReconciliationTrigger::Unknown;
        $executeMetadata = $connection->table('integration_operation_attempts')
            ->where('operation_id', $claim->operationId->value)
            ->where('mode', LeasePurpose::Execute->value)
            ->whereNotNull('safe_metadata')
            ->orderByDesc('attempt_no')
            ->value('safe_metadata');

        if (is_string($executeMetadata)) {
            $triggerValue = $this->safeMetadata($executeMetadata)['reconciliation_trigger'] ?? null;

            if (is_string($triggerValue)) {
                $trigger = ReconciliationTrigger::tryFrom($triggerValue)
                    ?? throw new OperationPersistenceFailed;
            }
        }

        return new StoredAuthoritativeReconciliationContext(
            $loaded->view,
            $loaded->observationNumber,
            $this->utc($runtime->request_started_at),
            $this->utc($runtime->observation_started_at),
            $observations,
            $trigger,
        );
    }

    /** @return array<string, mixed> */
    private function safeMetadata(string $encoded): array
    {
        try {
            $metadata = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OperationPersistenceFailed;
        }

        if (! is_array($metadata) || array_is_list($metadata) || count($metadata) > 4) {
            throw new OperationPersistenceFailed;
        }

        return $metadata;
    }

    private function utc(string $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    private function poll(LoadedOperation $loaded): void
    {
        $definition = $this->authoritativeDefinition($loaded);
        $finalizer = $this->pollFinalizer;

        if ($definition === null || $finalizer === null) {
            throw new OperationPersistenceFailed;
        }

        try {
            $context = $this->pollingContext($loaded);
            $strategy = $this->resolveAuthoritative(
                $definition,
                $definition->pollingStrategy,
                PollingStrategy::class,
            );
            $outcome = $this->providerCall(
                fn (): PollOutcome => $strategy->poll($context),
            );
            $planner = $this->resolveAuthoritative(
                $definition,
                $definition->observationProjection?->planner,
                ObservationProjectionPlanner::class,
            );
            $projection = $this->providerCall(
                fn (): ObservationProjectionPlan => $planner->plan(
                    new ObservationProjectionInput($loaded->view, $outcome),
                ),
            );
            $projector = $definition->observationProjector === null
                ? null
                : $this->resolveAuthoritative(
                    $definition,
                    $definition->observationProjector,
                    ObservationProjector::class,
                );
            $encodedResult = $this->encodeAuthoritativePollResult($definition, $outcome);
            $finalizer->finalize($loaded, $definition, $outcome, $encodedResult, $projection, $projector);
        } catch (Throwable) {
            $finalizer->runtimeFailure($loaded, $definition, 'poll_runtime_contract');
        }
    }

    private function pollingContext(LoadedOperation $loaded): StoredPollingContext
    {
        $claim = $loaded->lease->claim();
        $state = $this->database->connection()
            ->table('integration_operation_authoritative_states')
            ->where('operation_id', $claim->operationId->value)
            ->first([
                'poll_purpose',
                'poll_attempts',
                'last_polled_at',
                'poll_deadline_at',
            ]);

        if (! $state instanceof stdClass
            || ! is_string($state->poll_purpose ?? null)
            || ! is_int($state->poll_attempts ?? null)
            || ! is_string($state->last_polled_at ?? null)
            || ! is_string($state->poll_deadline_at ?? null)
            || $state->poll_attempts !== $loaded->observationNumber) {
            throw new OperationPersistenceFailed;
        }

        $purpose = PollPurpose::tryFrom($state->poll_purpose)
            ?? throw new OperationPersistenceFailed;

        return new StoredPollingContext(
            $loaded->view,
            $purpose,
            $loaded->observationNumber,
            $state->last_polled_at,
            $state->poll_deadline_at,
        );
    }

    private function authoritativeDefinition(LoadedOperation $loaded): ?AuthoritativeOperationDefinition
    {
        $definitions = $this->authoritativeDefinitions;

        if ($definitions === null || ! $definitions->isFrozen()) {
            return null;
        }

        $definition = $definitions->find(
            $loaded->lease->claim()->scope->provider,
            $loaded->view->operationType(),
            $loaded->definition->versions->handler,
        );

        if ($definition === null
            || $definition->versions->payloadSchema !== $loaded->definition->versions->payloadSchema
            || $definition->versions->resultSchema !== $loaded->definition->versions->resultSchema
            || $definition->maximumRemoteWrites !== $loaded->definition->maximumRemoteWrites) {
            return null;
        }

        return $definition;
    }

    private function encodeAuthoritativePollResult(
        AuthoritativeOperationDefinition $definition,
        PollOutcome $outcome,
    ): ?EncodedResult {
        if ($outcome->operationResult === null) {
            return null;
        }

        $codec = $this->resolveAuthoritative(
            $definition,
            $definition->resultEnvelope->resultCodec,
            OperationResultCodec::class,
        );
        $encoded = $this->providerCall(
            fn (): EncodedResult => $codec->encode($outcome->operationResult),
        );

        if ($encoded->resultType !== $definition->resultEnvelope->resultType
            || $encoded->schemaVersion !== $definition->resultEnvelope->schemaVersion
            || $encoded->canonicalByteLength() > $definition->resultEnvelope->maximumPlaintextBytes
            || ! hash_equals($outcome->operationResult->resultType(), $encoded->resultType)) {
            throw new OperationPersistenceFailed;
        }

        return $encoded;
    }

    /**
     * @return array{ExecutionOutcome|null, EncodedResult|null, OutcomeProjector|null, ProjectionPlan|null}
     */
    private function authoritativeReconciliationResult(
        LoadedOperation $loaded,
        AuthoritativeOperationDefinition $definition,
        AuthoritativeReconciliationOutcome $reconciliation,
    ): array {
        $result = $reconciliation->operationResult;

        if ($result === null) {
            return [null, null, null, null];
        }

        $terminalOutcome = match ($reconciliation->result) {
            AuthoritativeReconciliationResult::FoundExact => new TerminalOutcomePair(
                OperationStatus::Succeeded,
                EffectState::Applied,
                ResultAvailability::Available,
                [TerminalProofKind::Reconcile],
            ),
            AuthoritativeReconciliationResult::ProviderRejected => new TerminalOutcomePair(
                OperationStatus::Failed,
                EffectState::Applied,
                ResultAvailability::Available,
                [TerminalProofKind::Reconcile],
            ),
            default => throw new OperationPersistenceFailed,
        };

        if (! $definition->terminalOutcomes->allows(
            $terminalOutcome,
            TerminalProofKind::Reconcile,
        )) {
            throw new OperationPersistenceFailed;
        }

        $codec = $this->resolveAuthoritative(
            $definition,
            $definition->resultEnvelope->resultCodec,
            OperationResultCodec::class,
        );
        $encodedResult = $this->providerCall(fn (): EncodedResult => $codec->encode($result));

        if ($encodedResult->resultType !== $definition->resultEnvelope->resultType
            || $encodedResult->schemaVersion !== $definition->resultEnvelope->schemaVersion
            || $encodedResult->canonicalByteLength() > $definition->resultEnvelope->maximumPlaintextBytes
            || ! hash_equals($result->resultType(), $encodedResult->resultType)) {
            throw new OperationPersistenceFailed;
        }

        $planner = $this->resolveAuthoritative(
            $definition,
            $definition->projection->planner,
            OutcomeProjectionPlanner::class,
        );
        $projection = $this->providerCall(
            fn (): ProjectionPlan => $planner->plan(
                new ProjectionInput($loaded->view, $result, $terminalOutcome),
            ),
        );
        $projector = $this->resolve($loaded->definition->outcomeProjector, OutcomeProjector::class);

        return [new ExecutionOutcome($result), $encodedResult, $projector, $projection];
    }

    /** @return array{ObservationProjector|null, ObservationProjectionPlan|null} */
    private function authoritativeObservationProjection(
        LoadedOperation $loaded,
        AuthoritativeOperationDefinition $definition,
        AuthoritativeReconciliationOutcome $observation,
    ): array {
        if ($definition->observationProjection === null) {
            return [null, null];
        }

        $planner = $this->resolveAuthoritative(
            $definition,
            $definition->observationProjection->planner,
            ObservationProjectionPlanner::class,
        );
        $projection = $this->providerCall(
            fn (): ObservationProjectionPlan => $planner->plan(
                new ObservationProjectionInput($loaded->view, $observation),
            ),
        );
        $projector = $definition->observationProjector === null
            ? null
            : $this->resolveAuthoritative(
                $definition,
                $definition->observationProjector,
                ObservationProjector::class,
            );

        return [$projector, $projection];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function resolveAuthoritative(
        AuthoritativeOperationDefinition $definition,
        ?ServiceReference $reference,
        string $contract,
    ): object {
        $definitions = $this->authoritativeDefinitions;

        if ($definitions === null
            || $reference === null
            || ! $reference->targets($contract)
            || ! $definitions->runtimeBindingsAreAvailable(
                $definition,
                new ContainerBindingInspector($this->container),
            )) {
            throw new OperationPersistenceFailed;
        }

        $resolved = $definitions->resolveTrustedService($reference, $this->container);

        if (! $resolved instanceof $contract) {
            throw new OperationPersistenceFailed;
        }

        return $resolved;
    }

    /** @return array{ExecutionOutcome|null, EncodedResult|null, OutcomeProjector|null} */
    private function reconciliationResult(
        LoadedOperation $loaded,
        ReconciliationOutcome $reconciliation,
    ): array {
        if ($reconciliation->result !== ReconciliationResult::FoundExact) {
            return [null, null, null];
        }

        $result = $reconciliation->operationResult;

        if ($result === null) {
            throw new OperationPersistenceFailed;
        }

        $outcome = new ExecutionOutcome($result);
        $codec = $this->resolve($loaded->definition->resultCodec, OperationResultCodec::class);
        $projector = $this->resolve($loaded->definition->outcomeProjector, OutcomeProjector::class);

        return [$outcome, $this->encode($loaded, $codec, $outcome), $projector];
    }

    private function encode(
        LoadedOperation $loaded,
        OperationResultCodec $codec,
        ExecutionOutcome $outcome,
    ): EncodedResult {
        $encoded = $this->providerCall(fn (): EncodedResult => $codec->encode($outcome->result));

        if ($encoded->resultType !== $codec::resultType()
            || $encoded->schemaVersion !== $codec::schemaVersion()
            || $encoded->schemaVersion !== $loaded->definition->versions->resultSchema
            || ! hash_equals($outcome->result->resultType(), $encoded->resultType)) {
            throw new OperationPersistenceFailed;
        }

        return $encoded;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private function resolve(?ServiceReference $reference, string $contract): object
    {
        if ($reference === null || ! $reference->targets($contract)) {
            throw new OperationPersistenceFailed;
        }

        $resolved = $this->definitions->resolveTrustedService($reference, $this->container);

        if (! $resolved instanceof $contract) {
            throw new OperationPersistenceFailed;
        }

        return $resolved;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function providerCall(callable $callback): mixed
    {
        $baseline = $this->database->transactionLevels();

        try {
            $result = $callback();
        } catch (Throwable $failure) {
            $this->restoreProviderTransactions($baseline);

            throw $failure;
        }

        if ($this->database->transactionLevels() !== $baseline) {
            $this->restoreProviderTransactions($baseline);

            throw new OperationPersistenceFailed;
        }

        return $result;
    }

    /** @param array<string, int> $baseline */
    private function restoreProviderTransactions(array $baseline): void
    {
        try {
            $this->database->restoreTransactionLevels($baseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }
    }

    private function assertNoRuntimeTransaction(): void
    {
        $this->database->assertNoForeignTransaction();

        foreach ($this->database->transactionLevels() as $level) {
            if ($level !== 0) {
                throw new OperationPersistenceFailed;
            }
        }
    }

    private function workerIdentity(): string
    {
        $processId = getmypid();

        return 'kernel-worker:'.(is_int($processId) ? $processId : 0);
    }
}
