<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\RetryDecision;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Cieplik206\IntegrationOperations\Exceptions\OperationConcurrencyViolation;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseOperationFinalizer
{
    public function __construct(
        private KernelDatabase $database,
        private BoundPayloadEnvelopeCodec $envelopes,
        private OperationStateMachine $stateMachine,
        private DatabaseTransitionRecorder $transitions,
        private UlidFactory $ulids,
        private Dispatcher $events,
        private Repository $config,
    ) {}

    public function succeed(
        LoadedOperation $loaded,
        ExecutionOutcome $outcome,
        EncodedResult $encodedResult,
        OutcomeProjector $projector,
    ): void {
        $definition = $loaded->definition;
        $targetEffect = $definition->maximumRemoteWrites === 0
            ? EffectState::NotStarted
            : EffectState::Applied;
        $envelope = $this->envelopes->encrypt(
            new PayloadEnvelopeBinding(
                'result',
                $loaded->lease->claim()->operationId,
                1,
                $encodedResult->schemaVersion,
            ),
            new CanonicalObject($encodedResult->toArray()),
        );

        $this->transaction(function (Connection $connection) use (
            $loaded,
            $outcome,
            $encodedResult,
            $envelope,
            $projector,
            $targetEffect,
        ): void {
            [$operation, $attempt, $fromStatus, $fromEffect, $observedAt] = $this->lockClaimedLifecycle($connection, $loaded);

            if (($loaded->definition->maximumRemoteWrites === 1 && $fromEffect !== EffectState::PossiblyApplied)
                || ($loaded->definition->maximumRemoteWrites === 0 && $fromEffect !== EffectState::NotStarted)) {
                throw new OperationConcurrencyViolation;
            }

            $transition = $this->stateMachine->transition(
                $fromStatus,
                $fromEffect,
                OperationStatus::Succeeded,
                $targetEffect,
                $loaded->definition->maximumRemoteWrites,
            );
            $transactionLevel = $connection->transactionLevel();
            $projector->project($loaded->view, $outcome);

            if ($connection->transactionLevel() !== $transactionLevel) {
                throw new OperationConcurrencyViolation;
            }

            $this->insertResult($connection, $loaded, $encodedResult, $envelope, $observedAt);
            $this->finishAttempt(
                $connection,
                $loaded,
                $attempt,
                'succeeded',
                $targetEffect,
                $observedAt,
            );
            $this->updateOperation(
                $connection,
                $loaded,
                $operation,
                $transition,
                $targetEffect,
                $observedAt,
                null,
                null,
            );
        });
    }

    public function failExecution(
        LoadedOperation $loaded,
        FailureClassification $classification,
        RetryInstruction $instruction,
    ): void {
        $this->transaction(function (Connection $connection) use ($loaded, $classification, $instruction): void {
            [$operation, $attempt, $fromStatus, $fromEffect, $observedAt] = $this->lockClaimedLifecycle($connection, $loaded);
            [$toStatus, $toEffect, $reasonCode] = $this->classifiedTarget(
                $classification,
                $instruction,
                $fromEffect,
            );
            $nextAttemptAt = $this->nextAttemptAt($connection, $toStatus, $observedAt);
            $transition = $this->stateMachine->transition(
                $fromStatus,
                $fromEffect,
                $toStatus,
                $toEffect,
                $loaded->definition->maximumRemoteWrites,
            );

            $this->finishAttempt(
                $connection,
                $loaded,
                $attempt,
                $classification->disposition->value,
                $toEffect,
                $observedAt,
                $classification->safeFailure,
            );
            $this->updateOperation(
                $connection,
                $loaded,
                $operation,
                $transition,
                $toEffect,
                $observedAt,
                $classification->safeFailure,
                $nextAttemptAt,
                $reasonCode,
            );
        });
    }

    public function reconcile(
        LoadedOperation $loaded,
        ReconciliationOutcome $reconciliation,
        ?ExecutionOutcome $outcome,
        ?EncodedResult $encodedResult,
        ?OutcomeProjector $projector,
    ): void {
        $this->transaction(function (Connection $connection) use (
            $loaded,
            $reconciliation,
            $outcome,
            $encodedResult,
            $projector,
        ): void {
            [$operation, $attempt, $fromStatus, $fromEffect, $observedAt] = $this->lockClaimedLifecycle($connection, $loaded);

            if ($fromEffect !== EffectState::PossiblyApplied
                || ! in_array($reconciliation->result, $loaded->definition->reconciliationResults, true)) {
                throw new OperationConcurrencyViolation;
            }

            [$toStatus, $toEffect, $safeFailure] = match ($reconciliation->result) {
                ReconciliationResult::FoundExact => [OperationStatus::Succeeded, EffectState::Applied, null],
                ReconciliationResult::AbsentConclusive => [OperationStatus::Failed, EffectState::NotApplied, $reconciliation->safeFailure],
                ReconciliationResult::Inconclusive => [OperationStatus::Uncertain, EffectState::PossiblyApplied, null],
                ReconciliationResult::AmbiguousMatches => [OperationStatus::ManualReview, EffectState::PossiblyApplied, $reconciliation->safeFailure],
            };
            $transition = $this->stateMachine->transition(
                $fromStatus,
                $fromEffect,
                $toStatus,
                $toEffect,
                $loaded->definition->maximumRemoteWrites,
            );

            if ($reconciliation->result === ReconciliationResult::FoundExact) {
                if (! $outcome instanceof ExecutionOutcome
                    || ! $encodedResult instanceof EncodedResult
                    || ! $projector instanceof OutcomeProjector) {
                    throw new OperationConcurrencyViolation;
                }

                $transactionLevel = $connection->transactionLevel();
                $projector->project($loaded->view, $outcome);

                if ($connection->transactionLevel() !== $transactionLevel) {
                    throw new OperationConcurrencyViolation;
                }

                $envelope = $this->envelopes->encrypt(
                    new PayloadEnvelopeBinding(
                        'result',
                        $loaded->lease->claim()->operationId,
                        1,
                        $encodedResult->schemaVersion,
                    ),
                    new CanonicalObject($encodedResult->toArray()),
                );
                $this->insertResult($connection, $loaded, $encodedResult, $envelope, $observedAt);
            }

            $this->finishAttempt(
                $connection,
                $loaded,
                $attempt,
                $reconciliation->result->value,
                $toEffect,
                $observedAt,
                $safeFailure,
                $reconciliation->evidenceCode,
            );
            $this->updateOperation(
                $connection,
                $loaded,
                $operation,
                $transition,
                $toEffect,
                $observedAt,
                $safeFailure,
                $this->nextAttemptAt($connection, $toStatus, $observedAt),
                'reconciliation_'.$reconciliation->result->value,
            );
        });
    }

    public function runtimeFailure(LoadedOperation $loaded, string $reasonCode): void
    {
        $failure = new SafeOperationFailure(
            'provider_runtime_contract',
            'The provider runtime contract could not be completed safely.',
        );

        $this->transaction(function (Connection $connection) use ($loaded, $failure, $reasonCode): void {
            [$operation, $attempt, $fromStatus, $fromEffect, $observedAt] = $this->lockClaimedLifecycle($connection, $loaded);
            $transition = $this->stateMachine->transition(
                $fromStatus,
                $fromEffect,
                OperationStatus::ManualReview,
                $fromEffect,
                $loaded->definition->maximumRemoteWrites,
            );

            $this->finishAttempt(
                $connection,
                $loaded,
                $attempt,
                'runtime_contract_failure',
                $fromEffect,
                $observedAt,
                $failure,
            );
            $this->updateOperation(
                $connection,
                $loaded,
                $operation,
                $transition,
                $fromEffect,
                $observedAt,
                $failure,
                null,
                $reasonCode,
            );
        });
    }

    /**
     * @return array{stdClass, stdClass, OperationStatus, EffectState, string}
     */
    private function lockClaimedLifecycle(Connection $connection, LoadedOperation $loaded): array
    {
        $claim = $loaded->lease->claim();
        $identity = $connection->table('integration_operations')
            ->where('id', $claim->operationId->value)
            ->first(['intent_id']);

        if (! $identity instanceof stdClass || ! is_string($identity->intent_id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $intent = $connection->table('integration_operation_intents')
            ->where('id', $identity->intent_id)
            ->where('provider', $claim->scope->provider->value)
            ->where('connection_key', $claim->scope->connection->value)
            ->lockForUpdate()
            ->first();
        $operation = $connection->table('integration_operations')
            ->where('id', $claim->operationId->value)
            ->lockForUpdate()
            ->first();
        $expectedStatus = $claim->purpose === LeasePurpose::Execute
            ? OperationStatus::Processing
            : OperationStatus::Reconciling;
        $tokenSha256 = hash('sha256', $claim->token());

        if (! $intent instanceof stdClass
            || ! $operation instanceof stdClass
            || ! is_string($operation->intent_id ?? null)
            || ! is_string($operation->provider ?? null)
            || ! is_string($operation->connection_key ?? null)
            || ! is_string($operation->operation_type ?? null)
            || ! is_int($operation->payload_schema_version ?? null)
            || ! is_int($operation->handler_version ?? null)
            || ! is_int($operation->result_schema_version ?? null)
            || ! is_int($operation->max_remote_writes ?? null)
            || ! is_string($operation->status ?? null)
            || ! is_string($operation->effect_state ?? null)
            || ! is_int($operation->row_version ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            || ! is_string($operation->lease_expires_at ?? null)
            || ! hash_equals($identity->intent_id, $operation->intent_id)
            || ! hash_equals($claim->scope->provider->value, $operation->provider)
            || ! hash_equals($claim->scope->connection->value, $operation->connection_key)
            || ! hash_equals($loaded->definition->operationType->value, $operation->operation_type)
            || $loaded->definition->versions->payloadSchema !== $operation->payload_schema_version
            || $loaded->definition->versions->handler !== $operation->handler_version
            || $loaded->definition->versions->resultSchema !== $operation->result_schema_version
            || $loaded->definition->maximumRemoteWrites !== $operation->max_remote_writes
            || $operation->status !== $expectedStatus->value
            || $operation->row_version !== $claim->rowVersion
            || ! hash_equals($claim->owner, $operation->lease_owner)
            || ! hash_equals($tokenSha256, $operation->lease_token_sha256)
            || ! $this->intentPointsToOperation($intent, $operation)) {
            throw new OperationConcurrencyViolation;
        }

        $attempt = $connection->table('integration_operation_attempts')
            ->where('id', $operation->active_attempt_id)
            ->where('operation_id', $claim->operationId->value)
            ->lockForUpdate()
            ->first();
        $fromStatus = OperationStatus::tryFrom($operation->status);
        $fromEffect = EffectState::tryFrom($operation->effect_state);

        if (! $attempt instanceof stdClass
            || ! $fromEffect instanceof EffectState
            || ! is_string($attempt->worker_identity ?? null)
            || ! is_string($attempt->lease_token_sha256 ?? null)
            || ! is_string($attempt->mode ?? null)
            || $attempt->finished_at !== null
            || ! hash_equals($claim->owner, $attempt->worker_identity)
            || ! hash_equals($tokenSha256, $attempt->lease_token_sha256)
            || ! hash_equals($claim->purpose->value, $attempt->mode)
            || ! $connection->table('integration_operations')
                ->where('id', $claim->operationId->value)
                ->where('row_version', $claim->rowVersion)
                ->where('lease_expires_at', '>', $connection->raw('CURRENT_TIMESTAMP'))
                ->exists()
            || $connection->table('integration_operation_results')
                ->where('operation_id', $claim->operationId->value)
                ->exists()) {
            throw new OperationConcurrencyViolation;
        }

        $observed = $connection->selectOne('SELECT CURRENT_TIMESTAMP(6) AS observed_at');

        if (! $observed instanceof stdClass || ! is_string($observed->observed_at ?? null)) {
            throw new OperationPersistenceFailed;
        }

        return [$operation, $attempt, $fromStatus, $fromEffect, $observed->observed_at];
    }

    /**
     * @return array{OperationStatus, EffectState, string}
     */
    private function classifiedTarget(
        FailureClassification $classification,
        RetryInstruction $instruction,
        EffectState $fromEffect,
    ): array {
        if ($instruction->decision === RetryDecision::Retry
            && in_array($classification->disposition, [
                FailureDisposition::RetryableRead,
                FailureDisposition::RequestNotStarted,
            ], true)
            && $fromEffect === EffectState::NotStarted) {
            return [OperationStatus::RetryWait, EffectState::NotStarted, 'execution_retry_scheduled'];
        }

        if ($instruction->decision === RetryDecision::Reconcile
            && $classification->disposition === FailureDisposition::Uncertain
            && $fromEffect === EffectState::PossiblyApplied) {
            return [OperationStatus::Uncertain, EffectState::PossiblyApplied, 'execution_reconciliation_scheduled'];
        }

        if ($instruction->decision === RetryDecision::Fail
            && $classification->disposition === FailureDisposition::NotApplied
            && $fromEffect === EffectState::PossiblyApplied) {
            return [OperationStatus::Failed, EffectState::NotApplied, 'execution_definitively_not_applied'];
        }

        if ($instruction->decision === RetryDecision::Fail
            && $classification->disposition === FailureDisposition::Permanent
            && in_array($fromEffect, [EffectState::NotStarted, EffectState::NotApplied], true)) {
            return [OperationStatus::Failed, $fromEffect, 'execution_permanent_failure'];
        }

        return [OperationStatus::ManualReview, $fromEffect, 'execution_manual_review'];
    }

    private function nextAttemptAt(Connection $connection, OperationStatus $status, string $observedAt): ?string
    {
        $configKey = match ($status) {
            OperationStatus::RetryWait => 'retry_delay_seconds',
            OperationStatus::Uncertain => 'reconciliation_delay_seconds',
            default => null,
        };

        if ($configKey === null) {
            return null;
        }

        $seconds = $this->config->get("integration-operations.runtime.{$configKey}");

        if (! is_int($seconds) || $seconds < 1 || $seconds > 86400) {
            throw new OperationPersistenceFailed;
        }

        $row = $connection->selectOne(
            "SELECT CAST(? AS timestamptz) + (? * INTERVAL '1 second') AS next_attempt_at",
            [$observedAt, $seconds],
        );

        if (! $row instanceof stdClass || ! is_string($row->next_attempt_at ?? null)) {
            throw new OperationPersistenceFailed;
        }

        return $row->next_attempt_at;
    }

    private function insertResult(
        Connection $connection,
        LoadedOperation $loaded,
        EncodedResult $encodedResult,
        EncryptedEnvelope $envelope,
        string $observedAt,
    ): void {
        $inserted = $connection->table('integration_operation_results')->insert([
            'operation_id' => $loaded->lease->claim()->operationId->value,
            'result_type' => $encodedResult->resultType,
            'result_schema_version' => $encodedResult->schemaVersion,
            'result_key_version' => $envelope->keyVersion,
            'result_cipher' => $envelope->cipher,
            'result_ciphertext' => $envelope->ciphertext,
            'result_ciphertext_sha256' => $envelope->contentDigest->hex,
            'created_at' => $observedAt,
        ]);

        if (! $inserted) {
            throw new OperationConcurrencyViolation;
        }
    }

    private function finishAttempt(
        Connection $connection,
        LoadedOperation $loaded,
        stdClass $attempt,
        string $safeOutcomeCategory,
        EffectState $effectAfter,
        string $observedAt,
        ?SafeOperationFailure $failure = null,
        ?string $evidenceCode = null,
    ): void {
        if (! is_string($attempt->id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $updated = $connection->table('integration_operation_attempts')
            ->where('id', $attempt->id)
            ->where('operation_id', $loaded->lease->claim()->operationId->value)
            ->whereNull('finished_at')
            ->whereNull('effect_state_after')
            ->update([
                'safe_outcome_category' => $safeOutcomeCategory,
                'effect_state_after' => $effectAfter->value,
                'response_received_at' => is_string($attempt->request_started_at ?? null) ? $observedAt : null,
                'error_category' => $failure === null ? null : 'provider',
                'error_code' => $failure?->code,
                'safe_metadata' => $evidenceCode === null
                    ? null
                    : json_encode(['evidence_code' => $evidenceCode], JSON_THROW_ON_ERROR),
                'finished_at' => $observedAt,
            ]);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }
    }

    private function updateOperation(
        Connection $connection,
        LoadedOperation $loaded,
        stdClass $operation,
        StateTransition $transition,
        EffectState $targetEffect,
        string $observedAt,
        ?SafeOperationFailure $failure,
        ?string $nextAttemptAt,
        ?string $reasonCode = null,
    ): void {
        if (! is_int($operation->row_version ?? null)
            || ! is_string($operation->active_attempt_id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $claim = $loaded->lease->claim();
        $nextRowVersion = $operation->row_version + 1;
        $targetStatus = $transition->toStatus;
        $updated = $connection->table('integration_operations')
            ->where('id', $claim->operationId->value)
            ->where('row_version', $operation->row_version)
            ->where('status', $transition->fromStatus?->value)
            ->where('effect_state', $transition->fromEffectState?->value)
            ->where('lease_owner', $claim->owner)
            ->where('lease_token_sha256', hash('sha256', $claim->token()))
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->update([
                'status' => $targetStatus->value,
                'disposition' => $targetStatus->disposition()->value,
                'effect_state' => $targetEffect->value,
                'next_attempt_at' => $nextAttemptAt,
                'last_error_category' => $failure === null ? null : 'provider',
                'last_error_code' => $failure?->code,
                'last_safe_failure_code' => $failure?->code,
                'last_safe_failure_summary' => $failure?->summary,
                'lease_owner' => null,
                'lease_token_sha256' => null,
                'lease_acquired_at' => null,
                'lease_heartbeat_at' => null,
                'lease_expires_at' => null,
                'active_attempt_id' => null,
                'completed_at' => $targetStatus->disposition()->isTerminal() ? $observedAt : null,
                'row_version' => $nextRowVersion,
                'updated_at' => $observedAt,
            ]);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $this->transitions->record(
            $connection,
            $claim->operationId,
            $transition,
            $operation->row_version,
            $nextRowVersion,
            $reasonCode ?? 'operation_finalized',
            occurredAt: $observedAt,
        );

        if ($targetStatus->disposition()->isTerminal() && $this->terminalEventsEnabled()) {
            $event = new OperationTerminalized(
                $this->ulids->generate(),
                $claim->operationId,
                $claim->scope,
                $targetStatus,
            );
            $connection->afterCommit(fn (): mixed => $this->events->dispatch($event));
        }

        $loaded->lease->advanceTo($nextRowVersion);
    }

    private function terminalEventsEnabled(): bool
    {
        $enabled = $this->config->get('integration-operations.events.enabled', true);

        if (! is_bool($enabled)) {
            throw new OperationPersistenceFailed;
        }

        return $enabled;
    }

    private function intentPointsToOperation(stdClass $intent, stdClass $operation): bool
    {
        return is_string($intent->current_operation_id ?? null)
            && is_int($intent->current_generation ?? null)
            && is_string($operation->id ?? null)
            && is_int($operation->intent_generation ?? null)
            && hash_equals($operation->id, $intent->current_operation_id)
            && $operation->intent_generation === $intent->current_generation;
    }

    /** @param Closure(Connection): void $callback */
    private function transaction(Closure $callback): void
    {
        $this->database->assertNoForeignTransaction();
        $connection = $this->database->connection();

        if ($this->transactionLevel($connection) !== 0) {
            throw new OperationPersistenceFailed;
        }

        try {
            $connection->transaction($callback, 3);
        } catch (Throwable $failure) {
            if ($this->transactionLevel($connection) !== 0) {
                try {
                    $connection->rollBack(0);
                } catch (Throwable) {
                    // The generic persistence exception below is the only public failure surface.
                }
            }

            if ($failure instanceof OperationPersistenceFailed) {
                throw $failure;
            }

            throw new OperationPersistenceFailed;
        }

        if ($this->transactionLevel($connection) !== 0) {
            throw new OperationPersistenceFailed;
        }
    }

    /** @phpstan-impure */
    private function transactionLevel(Connection $connection): int
    {
        return $connection->transactionLevel();
    }
}
