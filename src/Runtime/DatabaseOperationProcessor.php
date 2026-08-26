<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;
use Illuminate\Container\Container;
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
    ) {}

    public function process(OperationId $operationId): void
    {
        $this->assertNoRuntimeTransaction();
        $claim = $this->leases->claim($operationId, $this->workerIdentity());

        if ($claim === null) {
            return;
        }

        $loaded = $this->loader->load($claim);

        if ($claim->purpose === LeasePurpose::Execute) {
            $this->execute($loaded);

            return;
        }

        if ($claim->purpose === LeasePurpose::Reconcile) {
            $this->reconcile($loaded);

            return;
        }

        $this->finalizer->runtimeFailure($loaded, 'unsupported_lease_purpose');
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
