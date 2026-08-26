<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\OperationTelemetry;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OperationTelemetryEvent;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Exceptions\OperationConcurrencyViolation;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Telemetry\NullOperationTelemetry;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationTelemetryContext;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseAuthoritativePollLeaseManager
{
    public function __construct(
        private KernelDatabase $database,
        private AuthoritativeDefinitionRegistry $definitions,
        private ContainerBindingInspector $bindings,
        private AuthoritativeOperationStateMachine $stateMachine,
        private DatabaseTransitionRecorder $transitions,
        private LeaseTimingPolicy $timing,
        private UlidFactory $ulids,
        private OperationTelemetry $telemetry = new NullOperationTelemetry,
    ) {}

    public function claim(OperationId $operationId, string $owner): ?LeaseClaim
    {
        $this->database->assertNoForeignTransaction();
        $this->assertOwner($owner);
        $token = bin2hex(random_bytes(32));
        $tokenSha256 = hash('sha256', $token);

        try {
            $claim = $this->database->connection()->transaction(
                fn (Connection $connection): ?LeaseClaim => $this->claimTransaction(
                    $connection,
                    $operationId,
                    $owner,
                    $token,
                    $tokenSha256,
                ),
                3,
            );

            if ($claim !== null) {
                $this->telemetry->record(
                    OperationTelemetryEvent::Claimed,
                    new SafeOperationTelemetryContext(
                        $claim->scope->provider,
                        $claim->operationId,
                        status: OperationStatus::Polling,
                        reasonCode: 'lease_poll',
                    ),
                );
            }

            return $claim;
        } catch (Throwable $failure) {
            if ($failure instanceof OperationPersistenceFailed) {
                throw $failure;
            }

            throw new OperationPersistenceFailed;
        }
    }

    private function claimTransaction(
        Connection $connection,
        OperationId $operationId,
        string $owner,
        string $token,
        string $tokenSha256,
    ): ?LeaseClaim {
        $operation = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('status', OperationStatus::PollWait->value)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof stdClass) {
            return null;
        }

        $definition = $this->definition($operation);
        $polling = $definition->polling ?? throw new OperationConcurrencyViolation;
        $state = $connection->table('integration_operation_authoritative_states')
            ->where('operation_id', $operationId->value)
            ->lockForUpdate()
            ->first();
        $intent = is_string($operation->intent_id ?? null)
            ? $connection->table('integration_operation_intents')
                ->where('id', $operation->intent_id)
                ->lockForUpdate()
                ->first()
            : null;

        if (! $state instanceof stdClass
            || ! $intent instanceof stdClass
            || ! $this->operationIsCoherent($operation, $state, $intent, $definition, $operationId)) {
            throw new OperationConcurrencyViolation;
        }

        $unfinishedAttempt = $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->whereNull('finished_at')
            ->lockForUpdate()
            ->exists();
        $resultExists = $connection->table('integration_operation_results')
            ->where('operation_id', $operationId->value)
            ->lockForUpdate()
            ->exists();

        if ($unfinishedAttempt || $resultExists) {
            throw new OperationConcurrencyViolation;
        }

        $clock = $connection->selectOne(
            "SELECT clock_timestamp() AS observed_at, clock_timestamp() + (? * INTERVAL '1 second') AS deadline",
            [$this->timing->leaseSeconds()],
        );

        if (! $clock instanceof stdClass
            || ! is_string($clock->observed_at ?? null)
            || ! is_string($clock->deadline ?? null)
            || ! is_string($state->next_poll_at ?? null)
            || ! is_string($state->poll_deadline_at ?? null)
            || ! is_int($state->poll_attempts ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        if ($state->poll_attempts >= $polling->maximumAttempts
            || $state->poll_deadline_at <= $clock->observed_at) {
            $this->moveToManualReview($connection, $operation, $definition, $operationId, $clock->observed_at);

            return null;
        }

        if ($state->next_poll_at > $clock->observed_at) {
            return null;
        }

        $effectState = EffectState::tryFrom($operation->effect_state);

        if (! $effectState instanceof EffectState) {
            throw new OperationConcurrencyViolation;
        }

        $transition = $this->stateMachine->transition(
            OperationStatus::PollWait,
            $effectState,
            OperationStatus::Polling,
            $effectState,
            $definition->maximumRemoteWrites,
            $definition->successEffectPolicy,
        );
        $attemptId = $this->ulids->generate();
        $attemptNumber = ((int) $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->max('attempt_no')) + 1;
        $rowVersion = $operation->row_version;
        $nextRowVersion = $rowVersion + 1;

        $connection->table('integration_operation_attempts')->insert([
            'id' => $attemptId->value,
            'operation_id' => $operationId->value,
            'attempt_no' => $attemptNumber,
            'mode' => LeasePurpose::Poll->value,
            'effect_state_before' => $effectState->value,
            'started_at' => $clock->observed_at,
            'worker_identity' => $owner,
            'lease_token_sha256' => $tokenSha256,
        ]);

        $stateUpdated = $connection->table('integration_operation_authoritative_states')
            ->where('operation_id', $operationId->value)
            ->where('poll_attempts', $state->poll_attempts)
            ->where('next_poll_at', '<=', $clock->observed_at)
            ->where('poll_deadline_at', '>', $clock->observed_at)
            ->where('result_availability', ResultAvailability::NotReady->value)
            ->whereNull('terminal_proof_kind')
            ->update([
                'poll_attempts' => $state->poll_attempts + 1,
                'last_polled_at' => $clock->observed_at,
                'updated_at' => $clock->observed_at,
            ]);
        $operationUpdated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('row_version', $rowVersion)
            ->where('status', OperationStatus::PollWait->value)
            ->whereNull('lease_token_sha256')
            ->whereNull('active_attempt_id')
            ->update([
                'status' => OperationStatus::Polling->value,
                'disposition' => OperationStatus::Polling->disposition()->value,
                'row_version' => $nextRowVersion,
                'lease_owner' => $owner,
                'lease_token_sha256' => $tokenSha256,
                'lease_acquired_at' => $clock->observed_at,
                'lease_heartbeat_at' => $clock->observed_at,
                'lease_expires_at' => $clock->deadline,
                'active_attempt_id' => $attemptId->value,
                'last_attempt_id' => $attemptId->value,
                'updated_at' => $clock->observed_at,
            ]);

        if ($stateUpdated !== 1 || $operationUpdated !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $this->transitions->record(
            $connection,
            $operationId,
            $transition,
            $rowVersion,
            $nextRowVersion,
            'poll_claimed',
            occurredAt: $clock->observed_at,
        );

        return new LeaseClaim(
            $operationId,
            IntegrationScope::of($operation->provider, $operation->connection_key),
            LeasePurpose::Poll,
            $owner,
            $token,
            $nextRowVersion,
        );
    }

    private function definition(stdClass $operation): AuthoritativeOperationDefinition
    {
        if (! $this->definitions->isFrozen()
            || ! is_string($operation->provider ?? null)
            || ! is_string($operation->operation_type ?? null)
            || ! is_int($operation->handler_version ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        try {
            $definition = $this->definitions->find(
                new ProviderKey($operation->provider),
                new OperationType($operation->operation_type),
                $operation->handler_version,
            );
        } catch (InvalidArgumentException) {
            throw new OperationConcurrencyViolation;
        }

        if ($definition === null
            || $definition->polling === null
            || $definition->pollingStrategy === null
            || ! $this->definitions->runtimeBindingsAreAvailable($definition, $this->bindings)) {
            throw new OperationConcurrencyViolation;
        }

        return $definition;
    }

    private function operationIsCoherent(
        stdClass $operation,
        stdClass $state,
        stdClass $intent,
        AuthoritativeOperationDefinition $definition,
        OperationId $operationId,
    ): bool {
        return is_string($operation->id ?? null)
            && hash_equals($operationId->value, $operation->id)
            && is_string($operation->intent_id ?? null)
            && is_int($operation->intent_generation ?? null)
            && is_string($operation->provider ?? null)
            && is_string($operation->connection_key ?? null)
            && is_string($operation->operation_type ?? null)
            && is_int($operation->payload_schema_version ?? null)
            && is_int($operation->handler_version ?? null)
            && is_int($operation->result_schema_version ?? null)
            && is_int($operation->max_remote_writes ?? null)
            && is_int($operation->row_version ?? null)
            && is_string($operation->effect_state ?? null)
            && $operation->active_attempt_id === null
            && $operation->lease_token_sha256 === null
            && is_string($intent->current_operation_id ?? null)
            && is_int($intent->current_generation ?? null)
            && hash_equals($operationId->value, $intent->current_operation_id)
            && $operation->intent_generation === $intent->current_generation
            && $operation->provider === $definition->provider->value
            && $operation->operation_type === $definition->operationType->value
            && $operation->payload_schema_version === $definition->versions->payloadSchema
            && $operation->handler_version === $definition->versions->handler
            && $operation->result_schema_version === $definition->versions->resultSchema
            && $operation->max_remote_writes === $definition->maximumRemoteWrites
            && ($state->contract_version ?? null) === AuthoritativeOperationDefinition::ContractVersion
            && ($state->initial_lane ?? null) === $definition->initialLane->value
            && is_string($state->write_activation_slot ?? null)
            && $definition->writeActivation->forWriteActivationSlot($state->write_activation_slot) !== null
            && is_string($state->poll_purpose ?? null)
            && ($state->result_availability ?? null) === ResultAvailability::NotReady->value
            && ($state->terminal_proof_kind ?? null) === null;
    }

    private function moveToManualReview(
        Connection $connection,
        stdClass $operation,
        AuthoritativeOperationDefinition $definition,
        OperationId $operationId,
        string $observedAt,
    ): void {
        if (! is_int($operation->row_version ?? null)
            || ! is_string($operation->effect_state ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $effectState = EffectState::tryFrom($operation->effect_state)
            ?? throw new OperationConcurrencyViolation;
        $transition = $this->stateMachine->transition(
            OperationStatus::PollWait,
            $effectState,
            OperationStatus::ManualReview,
            $effectState,
            $definition->maximumRemoteWrites,
            $definition->successEffectPolicy,
        );
        $failure = new SafeOperationFailure(
            'poll_budget_exhausted',
            'The durable polling budget or deadline was exhausted.',
        );
        $nextRowVersion = $operation->row_version + 1;
        $updated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('row_version', $operation->row_version)
            ->where('status', OperationStatus::PollWait->value)
            ->update([
                'status' => OperationStatus::ManualReview->value,
                'disposition' => OperationStatus::ManualReview->disposition()->value,
                'last_error_category' => 'poll',
                'last_error_code' => $failure->code,
                'last_safe_failure_code' => $failure->code,
                'last_safe_failure_summary' => $failure->summary,
                'row_version' => $nextRowVersion,
                'updated_at' => $observedAt,
            ]);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $this->transitions->record(
            $connection,
            $operationId,
            $transition,
            $operation->row_version,
            $nextRowVersion,
            'poll_budget_exhausted',
            occurredAt: $observedAt,
        );
    }

    private function assertOwner(string $owner): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $owner) !== 1) {
            throw new InvalidArgumentException('Lease owner is invalid.');
        }
    }
}
