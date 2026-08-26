<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\RetryMode;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

/** @api */
final readonly class OperationDefinition
{
    /** @var list<string> */
    public array $safeRetryEvidence;

    /** @var list<ReconciliationResult> */
    public array $reconciliationResults;

    /**
     * @param  list<string>  $safeRetryEvidence
     * @param  list<ReconciliationResult>  $reconciliationResults
     */
    public function __construct(
        public ProviderKey $provider,
        public OperationType $operationType,
        public OperationDefinitionVersions $versions,
        public int $maximumRemoteWrites,
        public ?ManagedMutationIdentityContract $managedMutationIdentity,
        public BoundaryMode $boundaryMode,
        public RetryMode $retryMode,
        array $safeRetryEvidence,
        public AmbiguousEffectAction $ambiguousEffectAction,
        array $reconciliationResults,
        public TerminalContract $succeeded,
        public TerminalContract $failed,
        public TerminalContract $cancelled,
        public ?ServiceReference $handler,
        public ?ServiceReference $failureClassifier,
        public ?ServiceReference $retryPolicy,
        public ?ServiceReference $reconciliationStrategy,
        public ?ServiceReference $resultCodec,
        public ?ServiceReference $outcomeProjector,
    ) {
        $this->safeRetryEvidence = ImmutableValueSanitizer::stringList(
            $safeRetryEvidence,
            'Operation definition safe retry evidence',
        );
        $this->reconciliationResults = ImmutableValueSanitizer::enumList(
            $reconciliationResults,
            ReconciliationResult::class,
            'Operation definition reconciliation results',
        );
    }

    public static function readOnly(
        ProviderKey $provider,
        OperationType $operationType,
        OperationDefinitionVersions $versions,
        ServiceReference $handler,
        ServiceReference $failureClassifier,
        ServiceReference $retryPolicy,
        ServiceReference $resultCodec,
        ServiceReference $outcomeProjector,
    ): self {
        return new self(
            provider: $provider,
            operationType: $operationType,
            versions: $versions,
            maximumRemoteWrites: 0,
            managedMutationIdentity: null,
            boundaryMode: BoundaryMode::Forbidden,
            retryMode: RetryMode::ReadSafe,
            safeRetryEvidence: ['request_not_started', 'definitive_transient_read'],
            ambiguousEffectAction: AmbiguousEffectAction::NotApplicable,
            reconciliationResults: [],
            succeeded: new TerminalContract(
                OperationStatus::Succeeded,
                OperationDisposition::Succeeded,
                [EffectState::NotStarted],
                [ResultAvailability::Available],
            ),
            failed: new TerminalContract(
                OperationStatus::Failed,
                OperationDisposition::Failed,
                [EffectState::NotStarted],
                [ResultAvailability::NotApplicable],
            ),
            cancelled: new TerminalContract(
                OperationStatus::Cancelled,
                OperationDisposition::Cancelled,
                [EffectState::NotStarted],
                [ResultAvailability::NotApplicable],
            ),
            handler: $handler,
            failureClassifier: $failureClassifier,
            retryPolicy: $retryPolicy,
            reconciliationStrategy: null,
            resultCodec: $resultCodec,
            outcomeProjector: $outcomeProjector,
        );
    }

    public static function singleEffect(
        ProviderKey $provider,
        OperationType $operationType,
        OperationDefinitionVersions $versions,
        ManagedMutationIdentityContract $managedMutationIdentity,
        ServiceReference $handler,
        ServiceReference $failureClassifier,
        ServiceReference $retryPolicy,
        ServiceReference $reconciliationStrategy,
        ServiceReference $resultCodec,
        ServiceReference $outcomeProjector,
    ): self {
        return new self(
            provider: $provider,
            operationType: $operationType,
            versions: $versions,
            maximumRemoteWrites: 1,
            managedMutationIdentity: $managedMutationIdentity,
            boundaryMode: BoundaryMode::Required,
            retryMode: RetryMode::EffectAware,
            safeRetryEvidence: ['request_not_started'],
            ambiguousEffectAction: AmbiguousEffectAction::Reconcile,
            reconciliationResults: [
                ReconciliationResult::FoundExact,
                ReconciliationResult::AbsentConclusive,
                ReconciliationResult::Inconclusive,
                ReconciliationResult::AmbiguousMatches,
            ],
            succeeded: new TerminalContract(
                OperationStatus::Succeeded,
                OperationDisposition::Succeeded,
                [EffectState::Applied],
                [ResultAvailability::Available],
            ),
            failed: new TerminalContract(
                OperationStatus::Failed,
                OperationDisposition::Failed,
                [EffectState::NotStarted, EffectState::NotApplied],
                [ResultAvailability::NotApplicable],
            ),
            cancelled: new TerminalContract(
                OperationStatus::Cancelled,
                OperationDisposition::Cancelled,
                [EffectState::NotStarted],
                [ResultAvailability::NotApplicable],
            ),
            handler: $handler,
            failureClassifier: $failureClassifier,
            retryPolicy: $retryPolicy,
            reconciliationStrategy: $reconciliationStrategy,
            resultCodec: $resultCodec,
            outcomeProjector: $outcomeProjector,
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
            'operation_handler' => ['reference' => $this->handler, 'contract' => OperationHandler::class],
            'failure_classifier' => ['reference' => $this->failureClassifier, 'contract' => FailureClassifier::class],
            'retry_policy' => ['reference' => $this->retryPolicy, 'contract' => RetryPolicy::class],
            'reconciliation_strategy' => ['reference' => $this->reconciliationStrategy, 'contract' => ReconciliationStrategy::class],
            'result_codec' => ['reference' => $this->resultCodec, 'contract' => OperationResultCodec::class],
            'outcome_projector' => ['reference' => $this->outcomeProjector, 'contract' => OutcomeProjector::class],
        ];
    }
}
