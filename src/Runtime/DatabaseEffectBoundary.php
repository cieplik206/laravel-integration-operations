<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\EffectBoundaryFailure;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Exceptions\EffectBoundaryViolation;
use Cieplik206\IntegrationOperations\Exceptions\OperationConcurrencyViolation;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\RuntimeTransactionActive;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceUnavailable;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use LogicException;
use stdClass;
use Throwable;

/** @internal */
final class DatabaseEffectBoundary implements EffectBoundary
{
    private bool $opened = false;

    private ?EffectBoundaryFailure $terminalFailure = null;

    public function __construct(
        private readonly LeaseClaimHandle $lease,
        private readonly KernelDatabase $database,
        private readonly DefinitionRegistry $definitions,
        private readonly ContainerBindingInspector $bindings,
        private readonly DatabaseWriterFenceAuthority $writerFenceAuthority,
        private readonly WriterFenceResolver $configuredWriterFences,
        private readonly HmacSha256 $hmac,
        private readonly OperationStateMachine $stateMachine,
        private readonly DatabaseTransitionRecorder $transitions,
        private readonly LeaseTimingPolicy $timing,
    ) {}

    public function open(): void
    {
        if ($this->opened) {
            throw new EffectBoundaryViolation(EffectBoundaryFailure::AlreadyOpened);
        }

        if ($this->terminalFailure !== null) {
            throw new EffectBoundaryViolation($this->terminalFailure);
        }

        $this->assertRuntimeTransactionIsOutermost();
        $transactionBaseline = $this->database->transactionLevels();
        $claim = $this->lease->claim();
        $tokenSha256 = hash('sha256', $claim->token());

        try {
            $identity = $this->operationIdentity();

            if ($identity === null) {
                $decision = DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::LeaseLost);
            } else {
                $configuredWriterFence = $this->prepareWriterFenceSnapshot($identity, $transactionBaseline);
                $connection = $this->database->connection();
                $decision = $connection->transaction(
                    fn (): DatabaseEffectBoundaryDecision => $this->openTransaction(
                        $connection,
                        $identity,
                        $tokenSha256,
                        $configuredWriterFence,
                    ),
                    3,
                );

                if (! $this->transactionLevelsMatch($transactionBaseline)) {
                    throw new OperationPersistenceFailed;
                }
            }
        } catch (Throwable) {
            $this->reportPersistenceFailure($transactionBaseline);

            throw new OperationPersistenceFailed;
        }

        if (! $decision->opened) {
            $this->terminalFailure = $decision->failure
                ?? throw new LogicException('Missing rejected effect-boundary failure.');

            throw new EffectBoundaryViolation($this->terminalFailure);
        }

        $this->lease->advanceTo(
            $decision->rowVersion
                ?? throw new LogicException('Missing opened effect-boundary row version.'),
        );
        $this->opened = true;
    }

    public function wasOpened(): bool
    {
        return $this->opened;
    }

    private function openTransaction(
        Connection $connection,
        stdClass $identity,
        string $tokenSha256,
        DatabaseWriterFenceSnapshot $configuredWriterFence,
    ): DatabaseEffectBoundaryDecision {
        $operationId = $this->lease->claim()->operationId;
        try {
            $authority = $this->lockWriterFenceAuthority($connection, $identity);
            $authorityAliases = $authority === null
                ? null
                : $this->writerFenceAuthority->lockAliasesAndBackfillForBoundary(
                    $connection,
                    $authority,
                    $configuredWriterFence,
                );
        } catch (WriterFenceUnavailable) {
            $authority = null;
            $authorityAliases = null;
        }

        if ($authority === null || $authorityAliases === null) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::WriterFenceRejected);
        }

        $intent = $this->lockIntentForOperation($connection, $identity);

        if (! $intent instanceof stdClass) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::LeaseLost);
        }

        $operation = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof stdClass
            || ! $this->operationMatchesIdentity($operation, $identity, $operationId)
            || ! $this->operationMatchesScope($operation, $this->lease->claim()->scope)
            || ! $this->intentPointsToOperation($intent, $operation, $operationId)
            || ! $this->intentIdentityFieldsMatchOperation($intent, $operation)) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::LeaseLost);
        }

        if (($operation->request_started_at ?? null) !== null) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::AlreadyOpened);
        }

        if (! $this->claimStillOwnsLease($operation, $tokenSha256)) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::LeaseLost);
        }

        $attempt = $this->lockActiveAttempt($connection, $operation, $operationId);
        if (! $attempt instanceof stdClass
            || ! $this->attemptIsOpenExecute($attempt, $operation, $operationId)
            || ! $this->lastAttemptPointsToMaximum($connection, $operation, $operationId, $attempt)) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::InvalidState);
        }

        $result = $connection->table('integration_operation_results')
            ->where('operation_id', $operationId->value)
            ->lockForUpdate()
            ->first(['operation_id']);

        if ($result !== null) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::InvalidState);
        }

        $definition = $this->supportedDefinition($operation);

        if ($definition === null) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::InvalidState);
        }

        if (! $this->managedMutationIdentityMatches($intent, $operation, $definition)) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::InvalidState);
        }

        if ($definition->maximumRemoteWrites === 0 || $definition->boundaryMode === BoundaryMode::Forbidden) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::Forbidden);
        }

        if ($definition->maximumRemoteWrites !== 1
            || ! $this->definitions->runtimeBindingsAreAvailable($definition, $this->bindings)) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::InvalidState);
        }

        $writerFenceMatches = $this->writerFenceAuthority->configurationMatches(
            $authority,
            $authorityAliases,
            $configuredWriterFence,
        )
            && $this->writerFenceAuthority->operationMatches($authority, $authorityAliases, $operation)
            && $authority->ownerMode->permitsRemoteWrite();

        $decision = $this->databaseDecision(
            $connection,
            $operation,
            $this->timing->remoteCallBudgetSeconds(),
        );

        if (! $this->leaseMatches(
            $connection,
            $operation,
            $operationId,
            $tokenSha256,
            $decision->observedAt,
        )) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::LeaseLost);
        }

        if (! $this->attemptMatches($attempt, $operation, $operationId, $tokenSha256)) {
            return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::InvalidState);
        }

        if (! $writerFenceMatches) {
            return $this->auditWriterFenceRejection(
                $connection,
                $operation,
                $attempt,
                $operationId,
                $tokenSha256,
                $decision->observedAt,
            );
        }

        return $this->persistOpenedBoundary(
            $connection,
            $operation,
            $attempt,
            $operationId,
            $tokenSha256,
            $decision,
        );
    }

    private function persistOpenedBoundary(
        Connection $connection,
        stdClass $operation,
        stdClass $attempt,
        OperationId $operationId,
        string $tokenSha256,
        DatabaseLeaseDecision $decision,
    ): DatabaseEffectBoundaryDecision {
        if (! is_int($operation->row_version ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            || ! is_string($attempt->id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $rowVersion = $operation->row_version;
        $nextRowVersion = $rowVersion + 1;
        $updatedOperation = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('provider', $this->lease->claim()->scope->provider->value)
            ->where('connection_key', $this->lease->claim()->scope->connection->value)
            ->where('status', OperationStatus::Processing->value)
            ->where('effect_state', EffectState::NotStarted->value)
            ->where('row_version', $rowVersion)
            ->where('lease_owner', $this->lease->claim()->owner)
            ->where('lease_token_sha256', $tokenSha256)
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->whereNull('request_started_at')
            ->where('lease_expires_at', '>', $decision->observedAt)
            ->update([
                'effect_state' => EffectState::PossiblyApplied->value,
                'request_started_at' => $decision->observedAt,
                'lease_heartbeat_at' => $decision->observedAt,
                'lease_expires_at' => $decision->deadline,
                'row_version' => $nextRowVersion,
                'updated_at' => $decision->observedAt,
            ]);

        if ($updatedOperation !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $updatedAttempt = $connection->table('integration_operation_attempts')
            ->where('id', $attempt->id)
            ->where('operation_id', $operationId->value)
            ->where('mode', LeasePurpose::Execute->value)
            ->where('worker_identity', $this->lease->claim()->owner)
            ->where('lease_token_sha256', $tokenSha256)
            ->whereNull('finished_at')
            ->whereNull('request_started_at')
            ->whereNull('response_received_at')
            ->whereNull('effect_state_after')
            ->update(['request_started_at' => $decision->observedAt]);

        if ($updatedAttempt !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $transition = $this->stateMachine->transition(
            OperationStatus::Processing,
            EffectState::NotStarted,
            OperationStatus::Processing,
            EffectState::PossiblyApplied,
            1,
        );
        $this->transitions->record(
            $connection,
            $operationId,
            $transition,
            $rowVersion,
            $nextRowVersion,
            'effect_boundary_opened',
            occurredAt: $decision->observedAt,
        );

        return DatabaseEffectBoundaryDecision::opened($nextRowVersion);
    }

    private function auditWriterFenceRejection(
        Connection $connection,
        stdClass $operation,
        stdClass $attempt,
        OperationId $operationId,
        string $tokenSha256,
        string $observedAt,
    ): DatabaseEffectBoundaryDecision {
        if (! is_int($operation->row_version ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            || ! is_string($attempt->id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $rowVersion = $operation->row_version;
        $nextRowVersion = $rowVersion + 1;
        $finalizedAttempt = $connection->table('integration_operation_attempts')
            ->where('id', $attempt->id)
            ->where('operation_id', $operationId->value)
            ->where('mode', LeasePurpose::Execute->value)
            ->where('worker_identity', $this->lease->claim()->owner)
            ->where('lease_token_sha256', $tokenSha256)
            ->whereNull('finished_at')
            ->whereNull('request_started_at')
            ->whereNull('response_received_at')
            ->whereNull('effect_state_after')
            ->update([
                'safe_outcome_category' => 'writer_fence_rejected',
                'effect_state_after' => EffectState::NotStarted->value,
                'error_code' => 'effect_boundary_writer_fence_rejected',
                'finished_at' => $observedAt,
            ]);

        if ($finalizedAttempt !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $updated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('provider', $this->lease->claim()->scope->provider->value)
            ->where('connection_key', $this->lease->claim()->scope->connection->value)
            ->where('status', OperationStatus::Processing->value)
            ->where('effect_state', EffectState::NotStarted->value)
            ->where('row_version', $rowVersion)
            ->where('lease_owner', $this->lease->claim()->owner)
            ->where('lease_token_sha256', $tokenSha256)
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->whereNull('request_started_at')
            ->where('lease_expires_at', '>', $observedAt)
            ->update([
                'status' => OperationStatus::ManualReview->value,
                'disposition' => OperationStatus::ManualReview->disposition()->value,
                'last_error_category' => 'writer_fence',
                'last_error_code' => 'effect_boundary_writer_fence_rejected',
                'lease_owner' => null,
                'lease_token_sha256' => null,
                'lease_acquired_at' => null,
                'lease_heartbeat_at' => null,
                'lease_expires_at' => null,
                'active_attempt_id' => null,
                'row_version' => $nextRowVersion,
                'updated_at' => $observedAt,
            ]);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $transition = $this->stateMachine->transition(
            OperationStatus::Processing,
            EffectState::NotStarted,
            OperationStatus::ManualReview,
            EffectState::NotStarted,
            1,
        );
        $this->transitions->record(
            $connection,
            $operationId,
            $transition,
            $rowVersion,
            $nextRowVersion,
            'effect_boundary_writer_fence_rejected',
            occurredAt: $observedAt,
        );

        return DatabaseEffectBoundaryDecision::rejected(EffectBoundaryFailure::WriterFenceRejected);
    }

    private function operationIdentity(): ?stdClass
    {
        $operation = $this->database->connection()
            ->table('integration_operations')
            ->where('id', $this->lease->claim()->operationId->value)
            ->first();

        return $operation instanceof stdClass ? $operation : null;
    }

    private function lockIntentForOperation(Connection $connection, stdClass $identity): ?stdClass
    {
        if (! is_string($identity->provider ?? null)
            || ! is_string($identity->connection_key ?? null)
            || ! is_string($identity->intent_id ?? null)) {
            return null;
        }

        $intent = $connection->table('integration_operation_intents')
            ->where('provider', $identity->provider)
            ->where('connection_key', $identity->connection_key)
            ->where('id', $identity->intent_id)
            ->lockForUpdate()
            ->first();

        return $intent instanceof stdClass ? $intent : null;
    }

    private function lockActiveAttempt(Connection $connection, stdClass $operation, OperationId $operationId): ?stdClass
    {
        if (! is_string($operation->active_attempt_id ?? null)) {
            return null;
        }

        $attempt = $connection->table('integration_operation_attempts')
            ->where('id', $operation->active_attempt_id)
            ->where('operation_id', $operationId->value)
            ->lockForUpdate()
            ->first();

        return $attempt instanceof stdClass ? $attempt : null;
    }

    private function leaseMatches(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
        string $tokenSha256,
        string $observedAt,
    ): bool {
        $claim = $this->lease->claim();

        if ($claim->purpose !== LeasePurpose::Execute
            || ! is_string($operation->status ?? null)
            || ! is_string($operation->effect_state ?? null)
            || ! is_int($operation->row_version ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->lease_expires_at ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            || $operation->status !== OperationStatus::Processing->value
            || $operation->effect_state !== EffectState::NotStarted->value
            || $operation->row_version !== $claim->rowVersion
            || ! hash_equals($claim->owner, $operation->lease_owner)
            || ! hash_equals($tokenSha256, $operation->lease_token_sha256)) {
            return false;
        }

        return $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('provider', $claim->scope->provider->value)
            ->where('connection_key', $claim->scope->connection->value)
            ->where('status', OperationStatus::Processing->value)
            ->where('effect_state', EffectState::NotStarted->value)
            ->where('row_version', $claim->rowVersion)
            ->where('lease_owner', $claim->owner)
            ->where('lease_token_sha256', $tokenSha256)
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->whereNull('request_started_at')
            ->where('lease_expires_at', '>', $observedAt)
            ->exists();
    }

    private function claimStillOwnsLease(stdClass $operation, string $tokenSha256): bool
    {
        $claim = $this->lease->claim();

        return $claim->purpose === LeasePurpose::Execute
            && is_string($operation->status ?? null)
            && is_string($operation->effect_state ?? null)
            && is_int($operation->row_version ?? null)
            && is_string($operation->lease_owner ?? null)
            && is_string($operation->lease_token_sha256 ?? null)
            && $operation->status === OperationStatus::Processing->value
            && $operation->effect_state === EffectState::NotStarted->value
            && $operation->row_version === $claim->rowVersion
            && hash_equals($claim->owner, $operation->lease_owner)
            && hash_equals($tokenSha256, $operation->lease_token_sha256);
    }

    private function attemptMatches(
        stdClass $attempt,
        stdClass $operation,
        OperationId $operationId,
        string $tokenSha256,
    ): bool {
        return is_string($attempt->id ?? null)
            && is_string($attempt->operation_id ?? null)
            && is_string($attempt->mode ?? null)
            && is_string($attempt->effect_state_before ?? null)
            && is_string($attempt->worker_identity ?? null)
            && is_string($attempt->lease_token_sha256 ?? null)
            && is_string($operation->active_attempt_id ?? null)
            && hash_equals($operation->active_attempt_id, $attempt->id)
            && hash_equals($operationId->value, $attempt->operation_id)
            && $attempt->mode === LeasePurpose::Execute->value
            && $attempt->effect_state_before === EffectState::NotStarted->value
            && hash_equals($this->lease->claim()->owner, $attempt->worker_identity)
            && hash_equals($tokenSha256, $attempt->lease_token_sha256)
            && $attempt->finished_at === null
            && $attempt->request_started_at === null
            && $attempt->response_received_at === null
            && $attempt->effect_state_after === null;
    }

    private function attemptIsOpenExecute(
        stdClass $attempt,
        stdClass $operation,
        OperationId $operationId,
    ): bool {
        return is_string($attempt->id ?? null)
            && is_string($attempt->operation_id ?? null)
            && is_string($attempt->mode ?? null)
            && is_string($attempt->effect_state_before ?? null)
            && is_string($operation->active_attempt_id ?? null)
            && hash_equals($operation->active_attempt_id, $attempt->id)
            && hash_equals($operationId->value, $attempt->operation_id)
            && $attempt->mode === LeasePurpose::Execute->value
            && $attempt->effect_state_before === EffectState::NotStarted->value
            && $attempt->finished_at === null
            && $attempt->request_started_at === null
            && $attempt->response_received_at === null
            && $attempt->effect_state_after === null;
    }

    private function lastAttemptPointsToMaximum(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
        stdClass $activeAttempt,
    ): bool {
        if (! is_string($operation->last_attempt_id ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            || ! is_string($activeAttempt->id ?? null)
            || ! is_int($activeAttempt->attempt_no ?? null)
            || ! hash_equals($operation->active_attempt_id, $activeAttempt->id)
            || ! hash_equals($operation->last_attempt_id, $activeAttempt->id)) {
            return false;
        }

        $maximumAttemptNumber = $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->max('attempt_no');

        return is_int($maximumAttemptNumber)
            && $activeAttempt->attempt_no === $maximumAttemptNumber;
    }

    private function supportedDefinition(stdClass $operation): ?OperationDefinition
    {
        if (! $this->definitions->isFrozen()
            || ! is_string($operation->provider ?? null)
            || ! is_string($operation->operation_type ?? null)
            || ! is_int($operation->handler_version ?? null)
            || ! is_int($operation->payload_schema_version ?? null)
            || ! is_int($operation->result_schema_version ?? null)
            || ! is_int($operation->max_remote_writes ?? null)) {
            return null;
        }

        try {
            $definition = $this->definitions->find(
                new ProviderKey($operation->provider),
                new OperationType($operation->operation_type),
                $operation->handler_version,
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($definition === null
            || $definition->versions->payloadSchema !== $operation->payload_schema_version
            || $definition->versions->handler !== $operation->handler_version
            || $definition->versions->resultSchema !== $operation->result_schema_version
            || $definition->maximumRemoteWrites !== $operation->max_remote_writes) {
            return null;
        }

        return $definition;
    }

    private function lockWriterFenceAuthority(
        Connection $connection,
        stdClass $identity,
    ): ?DatabaseWriterFenceAuthorityRecord {
        if (! is_string($identity->provider ?? null)
            || ! is_string($identity->connection_key ?? null)
            || ! is_string($identity->operation_type ?? null)) {
            return null;
        }

        try {
            $scope = IntegrationScope::of($identity->provider, $identity->connection_key);
            $operationType = new OperationType($identity->operation_type);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $this->writerFenceAuthority->lockCurrentForClaim($connection, $scope, $operationType);
    }

    /** @param array<string, int> $transactionBaseline */
    private function prepareWriterFenceSnapshot(
        stdClass $identity,
        array $transactionBaseline,
    ): DatabaseWriterFenceSnapshot {
        $snapshot = DatabaseWriterFenceSnapshot::unavailable();
        $failed = false;

        try {
            if (! is_string($identity->operation_type ?? null)) {
                $failed = true;
            } else {
                $current = $this->configuredWriterFences->current(
                    $this->lease->claim()->scope,
                    new OperationType($identity->operation_type),
                );

                if (! $current instanceof WriterFence) {
                    $failed = true;
                } else {
                    $snapshot = $this->writerFenceSnapshot($current);
                }
            }
        } catch (Throwable) {
            $failed = true;
        }

        if (! $this->transactionLevelsMatch($transactionBaseline)) {
            $failed = true;
        }

        try {
            $this->database->restoreTransactionLevels($transactionBaseline);

            if (! $this->transactionLevelsMatch($transactionBaseline)) {
                throw new OperationPersistenceFailed;
            }
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        return $failed ? DatabaseWriterFenceSnapshot::unavailable() : $snapshot;
    }

    private function writerFenceSnapshot(WriterFence $current): DatabaseWriterFenceSnapshot
    {
        $cohort = $current->cohort();

        if ($cohort === null) {
            return DatabaseWriterFenceSnapshot::available(
                $current->generation,
                $current->ownerMode,
                null,
                null,
                DatabaseWriterFenceAliasSet::empty(),
            );
        }

        $digests = $this->hmac->readableDigests(LookupHmacDomain::Cohort, $cohort);
        $activeDigest = $this->hmac->digest(LookupHmacDomain::Cohort, $cohort);

        return DatabaseWriterFenceSnapshot::available(
            $current->generation,
            $current->ownerMode,
            $activeDigest->hex,
            $activeDigest->keyVersion,
            DatabaseWriterFenceAliasSet::fromDigests($digests),
        );
    }

    private function operationMatchesIdentity(stdClass $operation, stdClass $identity, OperationId $operationId): bool
    {
        return is_string($operation->id ?? null)
            && is_string($operation->provider ?? null)
            && is_string($operation->connection_key ?? null)
            && is_string($operation->intent_id ?? null)
            && is_int($operation->intent_generation ?? null)
            && is_string($identity->provider ?? null)
            && is_string($identity->connection_key ?? null)
            && is_string($identity->intent_id ?? null)
            && is_int($identity->intent_generation ?? null)
            && hash_equals($operationId->value, $operation->id)
            && hash_equals($identity->provider, $operation->provider)
            && hash_equals($identity->connection_key, $operation->connection_key)
            && hash_equals($identity->intent_id, $operation->intent_id)
            && $identity->intent_generation === $operation->intent_generation;
    }

    private function intentPointsToOperation(
        stdClass $intent,
        stdClass $operation,
        OperationId $operationId,
    ): bool {
        return is_string($intent->current_operation_id ?? null)
            && is_int($intent->current_generation ?? null)
            && is_int($operation->intent_generation ?? null)
            && hash_equals($operationId->value, $intent->current_operation_id)
            && $intent->current_generation === $operation->intent_generation;
    }

    private function intentIdentityFieldsMatchOperation(stdClass $intent, stdClass $operation): bool
    {
        return is_string($intent->id ?? null)
            && is_string($intent->provider ?? null)
            && is_string($intent->connection_key ?? null)
            && is_string($intent->operation_type ?? null)
            && is_string($intent->resource_type ?? null)
            && is_string($intent->semantic_slot ?? null)
            && is_string($operation->intent_id ?? null)
            && is_string($operation->provider ?? null)
            && is_string($operation->connection_key ?? null)
            && is_string($operation->operation_type ?? null)
            && is_string($operation->resource_type ?? null)
            && is_string($operation->semantic_slot ?? null)
            && hash_equals($intent->id, $operation->intent_id)
            && hash_equals($intent->provider, $operation->provider)
            && hash_equals($intent->connection_key, $operation->connection_key)
            && hash_equals($intent->operation_type, $operation->operation_type)
            && hash_equals($intent->resource_type, $operation->resource_type)
            && hash_equals($intent->semantic_slot, $operation->semantic_slot);
    }

    private function managedMutationIdentityMatches(
        stdClass $intent,
        stdClass $operation,
        OperationDefinition $definition,
    ): bool {
        if ($definition->maximumRemoteWrites === 0) {
            return $definition->managedMutationIdentity === null;
        }

        if ($definition->maximumRemoteWrites !== 1
            || $definition->managedMutationIdentity === null
            || ! is_string($operation->resource_type ?? null)
            || ! is_string($operation->semantic_slot ?? null)) {
            return false;
        }

        $localReferenceType = $intent->local_type ?? null;

        return ($localReferenceType === null || is_string($localReferenceType))
            && $definition->managedMutationIdentity->allowsPersisted(
                $operation->resource_type,
                $operation->semantic_slot,
                $localReferenceType,
            );
    }

    private function operationMatchesScope(stdClass $operation, IntegrationScope $scope): bool
    {
        return is_string($operation->provider ?? null)
            && is_string($operation->connection_key ?? null)
            && hash_equals($scope->provider->value, $operation->provider)
            && hash_equals($scope->connection->value, $operation->connection_key);
    }

    private function databaseDecision(
        Connection $connection,
        stdClass $operation,
        int $minimumDeadlineSeconds,
    ): DatabaseLeaseDecision {
        if (! is_string($operation->lease_expires_at ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $clock = $connection->selectOne(
            <<<'SQL'
                WITH decision AS MATERIALIZED (
                    SELECT clock_timestamp() AS observed_at
                )
                SELECT observed_at,
                    GREATEST(CAST(? AS timestamptz), observed_at + (? * INTERVAL '1 second')) AS deadline
                FROM decision
                SQL,
            [$operation->lease_expires_at, $minimumDeadlineSeconds],
        );

        if (! $clock instanceof stdClass
            || ! is_string($clock->observed_at ?? null)
            || ! is_string($clock->deadline ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        return new DatabaseLeaseDecision($clock->observed_at, $clock->deadline);
    }

    /** @param array<string, int> $transactionBaseline */
    private function reportPersistenceFailure(array $transactionBaseline): void
    {
        try {
            $this->database->restoreTransactionLevels($transactionBaseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        if (! $this->transactionLevelsMatch($transactionBaseline)) {
            throw new OperationPersistenceFailed;
        }

        try {
            report(new OperationPersistenceFailed);
        } catch (Throwable) {
        }

        try {
            $this->database->restoreTransactionLevels($transactionBaseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        if (! $this->transactionLevelsMatch($transactionBaseline)) {
            throw new OperationPersistenceFailed;
        }
    }

    /**
     * @phpstan-impure
     *
     * @param  array<string, int>  $transactionBaseline
     */
    private function transactionLevelsMatch(array $transactionBaseline): bool
    {
        $currentLevels = $this->database->transactionLevels();
        ksort($currentLevels);
        ksort($transactionBaseline);

        return $currentLevels === $transactionBaseline;
    }

    private function assertRuntimeTransactionIsOutermost(): void
    {
        $this->database->assertNoForeignTransaction();

        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new RuntimeTransactionActive;
        }
    }

    /** @return array{operation_id: string, scope: IntegrationScope, opened: bool, terminal_failure: string|null} */
    public function __debugInfo(): array
    {
        return [
            'operation_id' => $this->lease->claim()->operationId->value,
            'scope' => $this->lease->claim()->scope,
            'opened' => $this->opened,
            'terminal_failure' => $this->terminalFailure?->value,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Database effect boundaries cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Database effect boundaries cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Database effect boundaries cannot be cloned.');
    }
}
