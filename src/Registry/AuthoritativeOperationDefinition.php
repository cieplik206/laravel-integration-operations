<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\PollingStrategy;
use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\InitialOperationLane;
use Cieplik206\IntegrationOperations\Enums\RetryMode;
use Cieplik206\IntegrationOperations\Enums\SuccessEffectPolicy;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use InvalidArgumentException;

/** @api */
final readonly class AuthoritativeOperationDefinition
{
    public const int ContractVersion = 2;

    private const int MaximumTransportTargets = 8;

    private const int MaximumCompensationContracts = 16;

    /** @var list<string> */
    public array $safeRetryEvidence;

    /** @var list<AuthoritativeReconciliationResult> */
    public array $reconciliationResults;

    /** @var list<TransportTargetDefinition> */
    public array $transportTargets;

    /** @var list<CompensationContract> */
    public array $compensations;

    /**
     * @param  list<string>  $safeRetryEvidence
     * @param  list<AuthoritativeReconciliationResult>  $reconciliationResults
     * @param  list<TransportTargetDefinition>  $transportTargets
     * @param  list<CompensationContract>  $compensations
     */
    public function __construct(
        public ProviderKey $provider,
        public OperationType $operationType,
        public OperationDefinitionVersions $versions,
        public int $maximumRemoteWrites,
        public ?ManagedMutationIdentityContract $managedMutationIdentity,
        public BoundaryMode $boundaryMode,
        public InitialOperationLane $initialLane,
        public SuccessEffectPolicy $successEffectPolicy,
        public WriteActivationContract $writeActivation,
        public ?PollingContract $polling,
        public RetryMode $retryMode,
        array $safeRetryEvidence,
        public AmbiguousEffectAction $ambiguousEffectAction,
        array $reconciliationResults,
        public TerminalOutcomeContract $terminalOutcomes,
        public ResultEnvelopeDescriptor $resultEnvelope,
        array $transportTargets,
        public ProjectionContract $projection,
        public ?ProjectionContract $observationProjection,
        array $compensations,
        public ServiceReference $payloadCodec,
        public ServiceReference $handler,
        public ServiceReference $failureClassifier,
        public ServiceReference $retryPolicy,
        public ?ServiceReference $reconciliationStrategy,
        public ?ServiceReference $pollingStrategy,
    ) {
        $safeRetryEvidence = ImmutableValueSanitizer::stringList(
            $safeRetryEvidence,
            'Authoritative safe retry evidence',
        );
        $reconciliationResults = ImmutableValueSanitizer::enumList(
            $reconciliationResults,
            AuthoritativeReconciliationResult::class,
            'Authoritative reconciliation results',
        );

        if (count(array_unique($safeRetryEvidence, SORT_STRING)) !== count($safeRetryEvidence)) {
            throw new InvalidArgumentException('Authoritative safe retry evidence must be unique.');
        }

        $reconciliationResultValues = array_map(
            static fn (AuthoritativeReconciliationResult $result): string => $result->value,
            $reconciliationResults,
        );

        if (count(array_unique($reconciliationResultValues, SORT_STRING)) !== count($reconciliationResults)) {
            throw new InvalidArgumentException('Authoritative reconciliation results must be unique.');
        }

        sort($safeRetryEvidence, SORT_STRING);
        usort(
            $reconciliationResults,
            static fn (AuthoritativeReconciliationResult $left, AuthoritativeReconciliationResult $right): int => $left->value <=> $right->value,
        );
        $this->safeRetryEvidence = $safeRetryEvidence;
        $this->reconciliationResults = $reconciliationResults;
        $this->transportTargets = $this->canonicalTransportTargets(
            ImmutableValueSanitizer::objectList(
                $transportTargets,
                TransportTargetDefinition::class,
                'Authoritative transport targets',
            ),
        );
        $this->compensations = $this->canonicalCompensations(
            ImmutableValueSanitizer::objectList(
                $compensations,
                CompensationContract::class,
                'Authoritative compensation contracts',
            ),
        );
    }

    public function registryKey(): string
    {
        return "{$this->provider->value}|{$this->operationType->value}|{$this->versions->handler}";
    }

    /** @return array<string, array{reference: ServiceReference|null, contract: class-string}> */
    public function extensionPoints(): array
    {
        return [
            'payload_codec' => ['reference' => $this->payloadCodec, 'contract' => OperationPayloadCodec::class],
            'operation_handler' => ['reference' => $this->handler, 'contract' => OperationHandler::class],
            'authoritative_failure_classifier' => ['reference' => $this->failureClassifier, 'contract' => AuthoritativeFailureClassifier::class],
            'authoritative_retry_policy' => ['reference' => $this->retryPolicy, 'contract' => AuthoritativeRetryPolicy::class],
            'authoritative_reconciliation_strategy' => ['reference' => $this->reconciliationStrategy, 'contract' => AuthoritativeReconciliationStrategy::class],
            'polling_strategy' => ['reference' => $this->pollingStrategy, 'contract' => PollingStrategy::class],
            'result_codec' => ['reference' => $this->resultEnvelope->resultCodec, 'contract' => OperationResultCodec::class],
            'outcome_projection_planner' => ['reference' => $this->projection->planner, 'contract' => OutcomeProjectionPlanner::class],
            'observation_projection_planner' => ['reference' => $this->observationProjection?->planner, 'contract' => ObservationProjectionPlanner::class],
        ];
    }

    /**
     * @param  list<TransportTargetDefinition>  $targets
     * @return list<TransportTargetDefinition>
     */
    private function canonicalTransportTargets(array $targets): array
    {
        if (count($targets) > self::MaximumTransportTargets) {
            throw new InvalidArgumentException('Authoritative transport targets exceed their bound.');
        }

        $targetIds = array_map(
            static fn (TransportTargetDefinition $target): string => $target->targetId,
            $targets,
        );

        if (count(array_unique($targetIds, SORT_STRING)) !== count($targetIds)) {
            throw new InvalidArgumentException('Authoritative transport target IDs must be unique.');
        }

        usort(
            $targets,
            static fn (TransportTargetDefinition $left, TransportTargetDefinition $right): int => $left->targetId <=> $right->targetId,
        );

        return $targets;
    }

    /**
     * @param  list<CompensationContract>  $compensations
     * @return list<CompensationContract>
     */
    private function canonicalCompensations(array $compensations): array
    {
        if (count($compensations) > self::MaximumCompensationContracts) {
            throw new InvalidArgumentException('Authoritative compensation contracts exceed their bound.');
        }

        $slots = [];

        foreach ($compensations as $compensation) {
            if (! $compensation->parentType->equals($this->operationType)
                || ! $compensation->childType->belongsTo($this->provider)
                || isset($slots[$compensation->slot])) {
                throw new InvalidArgumentException('Authoritative compensation contract is inconsistent.');
            }

            $slots[$compensation->slot] = true;
        }

        usort(
            $compensations,
            static fn (CompensationContract $left, CompensationContract $right): int => [$left->slot, $left->childType->value] <=> [$right->slot, $right->childType->value],
        );

        return $compensations;
    }
}
