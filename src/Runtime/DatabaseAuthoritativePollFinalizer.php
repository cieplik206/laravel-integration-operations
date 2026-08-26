<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\OperationTelemetry;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OperationTelemetryEvent;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use Cieplik206\IntegrationOperations\Enums\PollResult;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Enums\WriteActivation;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Cieplik206\IntegrationOperations\Exceptions\OperationConcurrencyViolation;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\Telemetry\NullOperationTelemetry;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationTelemetryContext;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseAuthoritativePollFinalizer
{
    public function __construct(
        private KernelDatabase $database,
        private BoundPayloadEnvelopeCodec $envelopes,
        private AuthoritativeOperationStateMachine $stateMachine,
        private DatabaseTransitionRecorder $transitions,
        private UlidFactory $ulids,
        private Dispatcher $events,
        private Repository $config,
        private OperationTelemetry $telemetry = new NullOperationTelemetry,
    ) {}

    public function finalize(
        LoadedOperation $loaded,
        AuthoritativeOperationDefinition $definition,
        PollOutcome $outcome,
        ?EncodedResult $encodedResult,
        ObservationProjectionPlan $projection,
    ): void {
        if ($definition->observationProjection === null
            || ! $projection->isCompatibleWith($definition->observationProjection)
            || $projection->mutations !== []) {
            throw new OperationPersistenceFailed;
        }

        $this->transaction(function (Connection $connection) use (
            $loaded,
            $definition,
            $outcome,
            $encodedResult,
        ): void {
            [$operation, $state, $attempt, $effectState, $observedAt] = $this->lockLifecycle(
                $connection,
                $loaded,
                $definition,
            );
            [$targetStatus, $targetEffect, $availability, $proofKind, $failure, $reasonCode] = $this->target(
                $operation,
                $state,
                $definition,
                $outcome,
                $encodedResult,
                $effectState,
            );
            $transition = $this->stateMachine->transition(
                OperationStatus::Polling,
                $effectState,
                $targetStatus,
                $targetEffect,
                $definition->maximumRemoteWrites,
                $definition->successEffectPolicy,
            );

            if ($availability === ResultAvailability::Available) {
                if (! $encodedResult instanceof EncodedResult) {
                    throw new OperationConcurrencyViolation;
                }

                $this->insertResult($connection, $loaded, $encodedResult, $observedAt);
            }

            $nextPollAt = $targetStatus === OperationStatus::PollWait
                ? $this->nextPollAt($connection, $definition, $outcome, $state, $observedAt)
                : null;
            $this->finishAttempt(
                $connection,
                $loaded,
                $attempt,
                $outcome,
                $targetEffect,
                $failure,
                $observedAt,
            );
            $this->updateAuthoritativeState(
                $connection,
                $loaded,
                $availability,
                $proofKind,
                $nextPollAt,
                $observedAt,
            );
            $this->updateOperation(
                $connection,
                $loaded,
                $operation,
                $transition,
                $failure,
                $reasonCode,
                $observedAt,
            );
        });
    }

    public function runtimeFailure(
        LoadedOperation $loaded,
        AuthoritativeOperationDefinition $definition,
        string $reasonCode,
    ): void {
        $failure = new SafeOperationFailure(
            'provider_runtime_contract',
            'The provider polling runtime contract could not be completed safely.',
        );

        $this->transaction(function (Connection $connection) use ($loaded, $definition, $reasonCode, $failure): void {
            [$operation, , $attempt, $effectState, $observedAt] = $this->lockLifecycle(
                $connection,
                $loaded,
                $definition,
            );
            $transition = $this->stateMachine->transition(
                OperationStatus::Polling,
                $effectState,
                OperationStatus::ManualReview,
                $effectState,
                $definition->maximumRemoteWrites,
                $definition->successEffectPolicy,
            );
            $outcome = PollOutcome::manualReview($failure, $reasonCode);

            $this->finishAttempt(
                $connection,
                $loaded,
                $attempt,
                $outcome,
                $effectState,
                $failure,
                $observedAt,
            );
            $this->updateOperation(
                $connection,
                $loaded,
                $operation,
                $transition,
                $failure,
                $reasonCode,
                $observedAt,
            );
        });
    }

    /** @return array{stdClass, stdClass, stdClass, EffectState, string} */
    private function lockLifecycle(
        Connection $connection,
        LoadedOperation $loaded,
        AuthoritativeOperationDefinition $definition,
    ): array {
        $claim = $loaded->lease->claim();
        $operation = $connection->table('integration_operations')
            ->where('id', $claim->operationId->value)
            ->lockForUpdate()
            ->first();
        $state = $connection->table('integration_operation_authoritative_states')
            ->where('operation_id', $claim->operationId->value)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof stdClass
            || ! $state instanceof stdClass
            || ! is_string($operation->active_attempt_id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $attempt = $connection->table('integration_operation_attempts')
            ->where('id', $operation->active_attempt_id)
            ->where('operation_id', $claim->operationId->value)
            ->lockForUpdate()
            ->first();
        $effectState = is_string($operation->effect_state ?? null)
            ? EffectState::tryFrom($operation->effect_state)
            : null;
        $clock = $connection->selectOne('SELECT clock_timestamp() AS observed_at');

        if (! $attempt instanceof stdClass
            || ! $effectState instanceof EffectState
            || ! $clock instanceof stdClass
            || ! is_string($clock->observed_at ?? null)
            || ! is_string($operation->provider ?? null)
            || ! is_string($operation->connection_key ?? null)
            || ! is_string($operation->operation_type ?? null)
            || ! is_int($operation->payload_schema_version ?? null)
            || ! is_int($operation->handler_version ?? null)
            || ! is_int($operation->result_schema_version ?? null)
            || ! is_int($operation->max_remote_writes ?? null)
            || ! is_int($operation->row_version ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->lease_expires_at ?? null)
            || $operation->status !== OperationStatus::Polling->value
            || $operation->row_version !== $claim->rowVersion
            || ! hash_equals($claim->scope->provider->value, $operation->provider)
            || ! hash_equals($claim->scope->connection->value, $operation->connection_key)
            || ! hash_equals($definition->operationType->value, $operation->operation_type)
            || $definition->versions->payloadSchema !== $operation->payload_schema_version
            || $definition->versions->handler !== $operation->handler_version
            || $definition->versions->resultSchema !== $operation->result_schema_version
            || $definition->maximumRemoteWrites !== $operation->max_remote_writes
            || ! hash_equals($claim->owner, $operation->lease_owner)
            || ! hash_equals(hash('sha256', $claim->token()), $operation->lease_token_sha256)
            || $operation->lease_expires_at <= $clock->observed_at
            || ($attempt->finished_at ?? null) !== null
            || ($attempt->mode ?? null) !== 'poll'
            || ($attempt->worker_identity ?? null) !== $claim->owner
            || ($attempt->lease_token_sha256 ?? null) !== hash('sha256', $claim->token())
            || ($state->result_availability ?? null) !== ResultAvailability::NotReady->value
            || ($state->terminal_proof_kind ?? null) !== null
            || ($state->poll_attempts ?? null) !== $loaded->observationNumber) {
            throw new OperationConcurrencyViolation;
        }

        return [$operation, $state, $attempt, $effectState, $clock->observed_at];
    }

    /**
     * @return array{OperationStatus, EffectState, ResultAvailability, TerminalProofKind|null, SafeOperationFailure|null, string}
     */
    private function target(
        stdClass $operation,
        stdClass $state,
        AuthoritativeOperationDefinition $definition,
        PollOutcome $outcome,
        ?EncodedResult $encodedResult,
        EffectState $effectState,
    ): array {
        return match ($outcome->result) {
            PollResult::Completed => $this->completedTarget($definition, $outcome, $encodedResult, $effectState),
            PollResult::ProviderRejected => $this->rejectedTarget($definition, $outcome, $encodedResult, $effectState),
            PollResult::Wait => $this->waitTarget($outcome, $effectState),
            PollResult::SendRequired => $this->sendRequiredTarget($operation, $state, $definition, $outcome, $effectState),
            PollResult::ManualReview => $this->manualReviewTarget($outcome, $effectState),
        };
    }

    /** @return array{OperationStatus, EffectState, ResultAvailability, TerminalProofKind, null, string} */
    private function completedTarget(
        AuthoritativeOperationDefinition $definition,
        PollOutcome $outcome,
        ?EncodedResult $encodedResult,
        EffectState $effectState,
    ): array {
        if ($outcome->operationResult === null
            || ! $encodedResult instanceof EncodedResult
            || $outcome->safeFailure !== null
            || $outcome->retryAfter !== null) {
            throw new OperationConcurrencyViolation;
        }

        $this->assertTerminalAllowed(
            $definition,
            OperationStatus::Succeeded,
            $effectState,
            ResultAvailability::Available,
            TerminalProofKind::Poll,
        );

        return [
            OperationStatus::Succeeded,
            $effectState,
            ResultAvailability::Available,
            TerminalProofKind::Poll,
            null,
            'poll_completed',
        ];
    }

    /** @return array{OperationStatus, EffectState, ResultAvailability, TerminalProofKind, SafeOperationFailure, string} */
    private function rejectedTarget(
        AuthoritativeOperationDefinition $definition,
        PollOutcome $outcome,
        ?EncodedResult $encodedResult,
        EffectState $effectState,
    ): array {
        if ($outcome->operationResult === null
            || ! $outcome->safeFailure instanceof SafeOperationFailure
            || ! $encodedResult instanceof EncodedResult
            || $outcome->retryAfter !== null) {
            throw new OperationConcurrencyViolation;
        }

        $this->assertTerminalAllowed(
            $definition,
            OperationStatus::Failed,
            $effectState,
            ResultAvailability::Available,
            TerminalProofKind::Poll,
        );

        return [
            OperationStatus::Failed,
            $effectState,
            ResultAvailability::Available,
            TerminalProofKind::Poll,
            $outcome->safeFailure,
            'poll_provider_rejected',
        ];
    }

    /** @return array{OperationStatus, EffectState, ResultAvailability, null, null, string} */
    private function waitTarget(PollOutcome $outcome, EffectState $effectState): array
    {
        if ($outcome->operationResult !== null || $outcome->safeFailure !== null) {
            throw new OperationConcurrencyViolation;
        }

        return [
            OperationStatus::PollWait,
            $effectState,
            ResultAvailability::NotReady,
            null,
            null,
            'poll_wait_scheduled',
        ];
    }

    /** @return array{OperationStatus, EffectState, ResultAvailability, null, null, string} */
    private function sendRequiredTarget(
        stdClass $operation,
        stdClass $state,
        AuthoritativeOperationDefinition $definition,
        PollOutcome $outcome,
        EffectState $effectState,
    ): array {
        $slot = $state->write_activation_slot ?? null;

        if ($outcome->operationResult !== null
            || $outcome->safeFailure !== null
            || $outcome->retryAfter !== null
            || ! is_string($slot)
            || $definition->writeActivation->forWriteActivationSlot($slot) !== WriteActivation::PollSendRequired
            || ($state->poll_purpose ?? null) !== PollPurpose::Preflight->value
            || $effectState !== EffectState::NotStarted
            || $definition->maximumRemoteWrites !== 1
            || ($operation->request_started_at ?? null) !== null) {
            throw new OperationConcurrencyViolation;
        }

        return [
            OperationStatus::Pending,
            EffectState::NotStarted,
            ResultAvailability::NotReady,
            null,
            null,
            'poll_send_required',
        ];
    }

    /** @return array{OperationStatus, EffectState, ResultAvailability, null, SafeOperationFailure, string} */
    private function manualReviewTarget(PollOutcome $outcome, EffectState $effectState): array
    {
        if ($outcome->operationResult !== null
            || ! $outcome->safeFailure instanceof SafeOperationFailure
            || $outcome->retryAfter !== null) {
            throw new OperationConcurrencyViolation;
        }

        return [
            OperationStatus::ManualReview,
            $effectState,
            ResultAvailability::NotReady,
            null,
            $outcome->safeFailure,
            'poll_manual_review',
        ];
    }

    private function assertTerminalAllowed(
        AuthoritativeOperationDefinition $definition,
        OperationStatus $status,
        EffectState $effectState,
        ResultAvailability $availability,
        TerminalProofKind $proofKind,
    ): void {
        $candidate = new TerminalOutcomePair($status, $effectState, $availability, [$proofKind]);

        if (! $definition->terminalOutcomes->allows($candidate, $proofKind)) {
            throw new OperationConcurrencyViolation;
        }
    }

    private function nextPollAt(
        Connection $connection,
        AuthoritativeOperationDefinition $definition,
        PollOutcome $outcome,
        stdClass $state,
        string $observedAt,
    ): string {
        $polling = $definition->polling ?? throw new OperationConcurrencyViolation;
        $hint = $outcome->retryAfter->value ?? $polling->minimumIntervalSeconds;
        $interval = max(
            $polling->minimumIntervalSeconds,
            min($hint, $polling->maximumIntervalSeconds),
        );

        if (! is_string($state->poll_deadline_at ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $next = $connection->selectOne(
            "SELECT LEAST(CAST(? AS timestamptz) + (? * INTERVAL '1 second'), CAST(? AS timestamptz)) AS next_poll_at",
            [$observedAt, $interval, $state->poll_deadline_at],
        );

        if (! $next instanceof stdClass || ! is_string($next->next_poll_at ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        return $next->next_poll_at;
    }

    private function insertResult(
        Connection $connection,
        LoadedOperation $loaded,
        EncodedResult $encodedResult,
        string $observedAt,
    ): void {
        $operationId = $loaded->lease->claim()->operationId;
        $envelope = $this->envelopes->encrypt(
            new PayloadEnvelopeBinding('result', $operationId, 1, $encodedResult->schemaVersion),
            new CanonicalObject($encodedResult->toArray()),
        );
        $inserted = $connection->table('integration_operation_results')->insert([
            'operation_id' => $operationId->value,
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
        PollOutcome $outcome,
        EffectState $effectState,
        ?SafeOperationFailure $failure,
        string $observedAt,
    ): void {
        if (! is_string($attempt->id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $updated = $connection->table('integration_operation_attempts')
            ->where('id', $attempt->id)
            ->where('operation_id', $loaded->lease->claim()->operationId->value)
            ->whereNull('finished_at')
            ->update([
                'safe_outcome_category' => $outcome->result->value,
                'effect_state_after' => $effectState->value,
                'error_category' => $failure === null ? null : 'provider',
                'error_code' => $failure?->code,
                'safe_metadata' => json_encode(
                    ['evidence_code' => $outcome->evidenceCode],
                    JSON_THROW_ON_ERROR,
                ),
                'finished_at' => $observedAt,
            ]);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }
    }

    private function updateAuthoritativeState(
        Connection $connection,
        LoadedOperation $loaded,
        ResultAvailability $availability,
        ?TerminalProofKind $proofKind,
        ?string $nextPollAt,
        string $observedAt,
    ): void {
        $updates = ['updated_at' => $observedAt];

        if ($nextPollAt !== null) {
            $updates['next_poll_at'] = $nextPollAt;
        }

        if ($proofKind !== null) {
            $updates['result_availability'] = $availability->value;
            $updates['terminal_proof_kind'] = $proofKind->value;
        }

        $updated = $connection->table('integration_operation_authoritative_states')
            ->where('operation_id', $loaded->lease->claim()->operationId->value)
            ->where('poll_attempts', $loaded->observationNumber)
            ->where('result_availability', ResultAvailability::NotReady->value)
            ->whereNull('terminal_proof_kind')
            ->update($updates);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }
    }

    private function updateOperation(
        Connection $connection,
        LoadedOperation $loaded,
        stdClass $operation,
        StateTransition $transition,
        ?SafeOperationFailure $failure,
        string $reasonCode,
        string $observedAt,
    ): void {
        if (! is_int($operation->row_version ?? null)
            || ! is_string($operation->active_attempt_id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $claim = $loaded->lease->claim();
        $targetStatus = $transition->toStatus;
        $nextRowVersion = $operation->row_version + 1;
        $updated = $connection->table('integration_operations')
            ->where('id', $claim->operationId->value)
            ->where('row_version', $operation->row_version)
            ->where('status', OperationStatus::Polling->value)
            ->where('lease_owner', $claim->owner)
            ->where('lease_token_sha256', hash('sha256', $claim->token()))
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->update([
                'status' => $targetStatus->value,
                'disposition' => $targetStatus->disposition()->value,
                'effect_state' => $transition->toEffectState->value,
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
            $reasonCode,
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

        $context = new SafeOperationTelemetryContext(
            $claim->scope->provider,
            $claim->operationId,
            $loaded->view->operationType(),
            $targetStatus,
            $targetStatus->disposition(),
            $transition->toEffectState,
            $reasonCode,
            $loaded->observationNumber,
        );
        $connection->afterCommit(function () use ($context): void {
            $this->telemetry->record(OperationTelemetryEvent::Polled, $context);
        });

        if ($targetStatus === OperationStatus::ManualReview) {
            $connection->afterCommit(function () use ($context): void {
                $this->telemetry->record(OperationTelemetryEvent::ManualReview, $context);
            });
        }

        if ($targetStatus->disposition()->isTerminal()) {
            $connection->afterCommit(function () use ($context): void {
                $this->telemetry->record(OperationTelemetryEvent::Terminalized, $context);
            });
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

    /** @param Closure(Connection): void $callback */
    private function transaction(Closure $callback): void
    {
        $this->database->assertNoForeignTransaction();
        $connection = $this->database->connection();

        try {
            $connection->transaction($callback, 3);
        } catch (Throwable $failure) {
            if ($failure instanceof OperationPersistenceFailed) {
                throw $failure;
            }

            throw new OperationPersistenceFailed;
        }
    }
}
