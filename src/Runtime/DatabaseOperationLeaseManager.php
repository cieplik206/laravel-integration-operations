<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\LeaseRecoveryDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Exceptions\LeaseRecoveryIncidentNotificationFailed;
use Cieplik206\IntegrationOperations\Exceptions\OperationConcurrencyViolation;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\OperationQuarantineUnavailable;
use Cieplik206\IntegrationOperations\Exceptions\RuntimeTransactionActive;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceUnavailable;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryBatch;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryCursor;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryIncident;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseOperationLeaseManager implements OperationLeaseManager
{
    public function __construct(
        private KernelDatabase $database,
        private DefinitionRegistry $definitions,
        private ContainerBindingInspector $bindings,
        private DatabaseWriterFenceAuthority $writerFences,
        private OperationStateMachine $stateMachine,
        private DatabaseTransitionRecorder $transitions,
        private LeaseRecoveryIncidentNotifier $incidents,
        private UlidFactory $ulids,
        private LeaseTimingPolicy $timing,
        private Repository $config,
    ) {}

    public function claim(OperationId $operationId, string $owner): ?LeaseClaim
    {
        $this->assertRuntimeTransactionIsOutermost();
        $transactionBaseline = $this->database->transactionLevels();
        $this->assertOwner($owner);
        $leaseSeconds = $this->timing->leaseSeconds();
        $token = bin2hex(random_bytes(32));
        $tokenSha256 = hash('sha256', $token);

        try {
            $identity = $this->operationIdentity($operationId);

            if ($identity === null || $this->supportedDefinition($identity) === null) {
                return null;
            }

            $connection = $this->database->connection();

            return $connection->transaction(
                fn (): ?LeaseClaim => $this->claimTransaction(
                    $connection,
                    $identity,
                    $operationId,
                    $owner,
                    $token,
                    $tokenSha256,
                    $leaseSeconds,
                ),
                3,
            );
        } catch (OperationConcurrencyViolation) {
            return null;
        } catch (Throwable $failure) {
            $this->reportPersistenceFailure($transactionBaseline);

            throw new OperationPersistenceFailed;
        }
    }

    public function heartbeat(LeaseClaim $claim): ?LeaseClaim
    {
        $this->assertRuntimeTransactionIsOutermost();
        $transactionBaseline = $this->database->transactionLevels();
        $leaseSeconds = $this->timing->leaseSeconds();
        $tokenSha256 = hash('sha256', $claim->token());

        try {
            $identity = $this->operationIdentity($claim->operationId);

            if ($identity === null || $this->supportedDefinition($identity, false) === null) {
                return null;
            }

            $connection = $this->database->connection();

            return $connection->transaction(function () use ($connection, $claim, $identity, $leaseSeconds, $tokenSha256): ?LeaseClaim {
                $intent = $this->lockIntentForOperation($connection, $identity);

                if (! $intent instanceof stdClass) {
                    return null;
                }

                $expectedStatus = $claim->purpose === LeasePurpose::Execute
                    ? OperationStatus::Processing
                    : OperationStatus::Reconciling;
                $operation = $connection->table('integration_operations')
                    ->where('id', $claim->operationId->value)
                    ->where('provider', $claim->scope->provider->value)
                    ->where('connection_key', $claim->scope->connection->value)
                    ->where('status', $expectedStatus->value)
                    ->where('lease_owner', $claim->owner)
                    ->where('lease_token_sha256', $tokenSha256)
                    ->where('row_version', $claim->rowVersion)
                    ->lockForUpdate()
                    ->first();

                $definition = $operation instanceof stdClass
                    ? $this->supportedDefinition($operation, false)
                    : null;

                if (! $operation instanceof stdClass
                    || ! $definition instanceof OperationDefinition
                    || ! is_string($operation->effect_state ?? null)
                    || ! is_int($operation->row_version ?? null)
                    || ! is_string($operation->active_attempt_id ?? null)
                    || ! $this->operationMatchesIdentity($operation, $identity, $claim->operationId)
                    || ! $this->intentPointsToOperation($intent, $operation, $claim->operationId)
                    || ! $this->intentIdentityMatchesOperation($intent, $operation, $definition)) {
                    return null;
                }

                $effect = EffectState::from($operation->effect_state);
                $activeAttemptMatches = $this->activeAttemptMatches(
                    $connection,
                    $operation,
                    $claim->operationId,
                    $claim->purpose,
                    $effect,
                );
                $result = $connection->table('integration_operation_results')
                    ->where('operation_id', $claim->operationId->value)
                    ->lockForUpdate()
                    ->first();

                if (! $activeAttemptMatches || $result !== null) {
                    return null;
                }

                $decision = $this->databaseDecision($connection, $leaseSeconds);

                if (! $this->leaseMatchesAtDecision(
                    $connection,
                    $claim,
                    $expectedStatus,
                    $operation->row_version,
                    $decision->observedAt,
                    $tokenSha256,
                )) {
                    return null;
                }

                $nextRowVersion = $operation->row_version + 1;
                $updated = $connection->table('integration_operations')
                    ->where('id', $claim->operationId->value)
                    ->where('provider', $claim->scope->provider->value)
                    ->where('connection_key', $claim->scope->connection->value)
                    ->where('status', $expectedStatus->value)
                    ->where('lease_owner', $claim->owner)
                    ->where('lease_token_sha256', $tokenSha256)
                    ->where('row_version', $operation->row_version)
                    ->where('lease_expires_at', '>', $decision->observedAt)
                    ->update([
                        'lease_heartbeat_at' => $decision->observedAt,
                        'lease_expires_at' => $decision->deadline,
                        'row_version' => $nextRowVersion,
                        'updated_at' => $decision->observedAt,
                    ]);

                if ($updated !== 1) {
                    return null;
                }

                $sameState = new StateTransition(
                    $expectedStatus,
                    $expectedStatus->disposition(),
                    $effect,
                    $expectedStatus,
                    $expectedStatus->disposition(),
                    $effect,
                );
                $this->transitions->record(
                    $connection,
                    $claim->operationId,
                    $sameState,
                    $operation->row_version,
                    $nextRowVersion,
                    'lease_heartbeat',
                    occurredAt: $decision->observedAt,
                );

                return $claim->withRowVersion($nextRowVersion);
            }, 3);
        } catch (Throwable $failure) {
            $this->reportPersistenceFailure($transactionBaseline);

            throw new OperationPersistenceFailed;
        }
    }

    public function recoverExpired(
        IntegrationScope $scope,
        int $limit = 100,
        int $scanLimit = 500,
        ?LeaseRecoveryCursor $after = null,
    ): LeaseRecoveryBatch {
        $this->assertRuntimeTransactionIsOutermost();
        if ($limit < 1 || $limit > 500 || $scanLimit < $limit || $scanLimit > 5000) {
            throw new InvalidArgumentException('Expired lease recovery bounds are invalid.');
        }

        if ($after !== null && ! $after->scope->equals($scope)) {
            throw new InvalidArgumentException('Lease recovery cursor scope does not match the requested scope.');
        }

        $transactionBaseline = $this->database->transactionLevels();

        try {
            $connection = $this->database->connection();
            $page = $this->expiredRecoveryCandidates($connection, $scope, $scanLimit, $after);
        } catch (Throwable) {
            $this->reportPersistenceFailure($transactionBaseline);

            throw new OperationPersistenceFailed;
        }
        $recovered = 0;
        $quarantined = 0;
        $deferred = 0;
        $skipped = 0;
        $scanned = 0;
        $cursor = $after;
        $incidents = [];

        foreach ($page['candidates'] as $candidate) {
            $cursor = $candidate;
            $scanned++;
            $outcome = $this->recoverOne($connection, $candidate, $transactionBaseline);

            if ($outcome->incident !== null) {
                $incidents[] = $outcome->incident;
            }

            match ($outcome->disposition) {
                LeaseRecoveryDisposition::Recovered => $recovered++,
                LeaseRecoveryDisposition::Quarantined => $quarantined++,
                LeaseRecoveryDisposition::Deferred => $deferred++,
                LeaseRecoveryDisposition::Skipped => $skipped++,
            };

            if ($recovered + $quarantined >= $limit) {
                break;
            }
        }

        $notificationFailures = 0;
        foreach ($incidents as $incident) {
            if (! $this->notifyRecoveryIncidentBestEffort($incident)) {
                $notificationFailures++;
            }
        }

        return new LeaseRecoveryBatch(
            scanned: $scanned,
            recovered: $recovered,
            quarantined: $quarantined,
            deferred: $deferred,
            skipped: $skipped,
            nextCursor: $scanned === 0 ? null : $cursor,
            exhausted: $scanned === count($page['candidates']) && ! $page['has_more'],
            notificationFailures: $notificationFailures,
        );
    }

    /**
     * @return array{candidates: list<LeaseRecoveryCursor>, has_more: bool}
     */
    private function expiredRecoveryCandidates(
        Connection $connection,
        IntegrationScope $scope,
        int $limit,
        ?LeaseRecoveryCursor $after,
    ): array {
        $query = $connection->table('integration_operations')
            ->select(['provider', 'connection_key', 'lease_expires_at', 'id'])
            ->where('provider', $scope->provider->value)
            ->where('connection_key', $scope->connection->value)
            ->whereRaw("id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'")
            ->whereRaw("intent_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'")
            ->whereRaw("operation_type ~ '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*){2,}$'")
            ->whereRaw("operation_type LIKE provider || '.%'")
            ->whereRaw("resource_type ~ '^[a-z][a-z0-9_.-]{0,127}$'")
            ->whereRaw("semantic_slot ~ '^[a-z][a-z0-9_.:-]{0,127}$'")
            ->whereIn('status', [OperationStatus::Processing->value, OperationStatus::Reconciling->value])
            ->whereIn('effect_state', [
                EffectState::NotStarted->value,
                EffectState::PossiblyApplied->value,
                EffectState::NotApplied->value,
                EffectState::Applied->value,
            ])
            ->where('lease_expires_at', '<=', $connection->raw('clock_timestamp()'))
            ->whereNotExists(function (Builder $query) use ($connection): void {
                $query->selectRaw('1')
                    ->from('integration_operation_attempts as recovery_backoff')
                    ->whereColumn('recovery_backoff.operation_id', 'integration_operations.id')
                    ->where('recovery_backoff.mode', 'recovery')
                    ->where('recovery_backoff.safe_outcome_category', 'deferred')
                    ->where('recovery_backoff.retry_after_at', '>', $connection->raw('clock_timestamp()'));
            });

        if ($after !== null) {
            $query->whereRaw(
                '(provider, connection_key, lease_expires_at, id) > (?, ?, CAST(? AS timestamptz), ?)',
                [
                    $after->scope->provider->value,
                    $after->scope->connection->value,
                    $after->leaseExpiresAt,
                    $after->operationId->value,
                ],
            );
        }

        $rows = $query
            ->orderBy('provider')
            ->orderBy('connection_key')
            ->orderBy('lease_expires_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $candidates = [];

        foreach ($rows->take($limit) as $row) {
            if (! is_string($row->provider ?? null)
                || ! is_string($row->connection_key ?? null)
                || ! is_string($row->lease_expires_at ?? null)
                || ! is_string($row->id ?? null)) {
                throw new OperationPersistenceFailed;
            }

            $candidates[] = LeaseRecoveryCursor::fromDatabase(
                IntegrationScope::of($row->provider, $row->connection_key),
                $row->lease_expires_at,
                new OperationId($row->id),
            );
        }

        return ['candidates' => $candidates, 'has_more' => $hasMore];
    }

    private function claimTransaction(
        Connection $connection,
        stdClass $identity,
        OperationId $operationId,
        string $owner,
        string $token,
        string $tokenSha256,
        int $leaseSeconds,
    ): ?LeaseClaim {
        try {
            $authority = $this->lockWriterFenceAuthority($connection, $identity);
            $authorityAliases = $authority === null
                ? null
                : $this->writerFences->lockAliases($connection, $authority);
        } catch (WriterFenceUnavailable) {
            $authority = null;
            $authorityAliases = null;
        }

        if ($authority === null || $authorityAliases === null) {
            return null;
        }

        $intent = $this->lockIntentForOperation($connection, $identity);

        if (! $intent instanceof stdClass) {
            return null;
        }

        $operation = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('provider', $identity->provider)
            ->where('connection_key', $identity->connection_key)
            ->lockForUpdate()
            ->first();

        $definition = $operation instanceof stdClass
            ? $this->supportedDefinition($operation)
            : null;

        if (! $operation instanceof stdClass
            || ! $definition instanceof OperationDefinition
            || ! $this->operationMatchesIdentity($operation, $identity, $operationId)
            || ! $this->intentPointsToOperation($intent, $operation, $operationId)
            || ! $this->intentIdentityMatchesOperation($intent, $operation, $definition)) {
            return null;
        }

        $writerFenceMatches = $this->writerFences->operationMatches($authority, $authorityAliases, $operation);

        if (! is_int($operation->row_version ?? null)
            || ! is_int($operation->max_remote_writes ?? null)
            || ! is_string($operation->status ?? null)
            || ! is_string($operation->effect_state ?? null)
            || ! is_int($operation->attempts ?? null)
            || ! is_int($operation->reconcile_attempts ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $unfinishedAttempt = $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->whereNull('finished_at')
            ->orderBy('attempt_no')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($unfinishedAttempt !== null
            || ! $this->lastAttemptIsCoherentForClaim($connection, $operation, $operationId)) {
            throw new OperationConcurrencyViolation;
        }

        $result = $connection->table('integration_operation_results')
            ->where('operation_id', $operationId->value)
            ->lockForUpdate()
            ->first();

        if ($result !== null) {
            throw new OperationConcurrencyViolation;
        }

        if (! $writerFenceMatches) {
            return $this->auditStaleWriterFenceAtClaim(
                $connection,
                $operation,
                $operationId,
                'writer_fence_stale_at_claim',
            );
        }

        $payload = $connection->table('integration_operation_payloads')
            ->where('operation_id', $operationId->value)
            ->where('payload_revision', $operation->current_payload_revision)
            ->lockForUpdate()
            ->first();

        if (! $payload instanceof stdClass
            || $payload->payload_pruned_at !== null
            || ! is_string($payload->payload_ciphertext ?? null)
            || ! is_string($payload->context_ciphertext ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $status = OperationStatus::from($operation->status);
        $effect = EffectState::from($operation->effect_state);
        $rowVersion = $operation->row_version;
        $decision = $this->databaseDecision($connection, $leaseSeconds);

        if (! $this->claimIsDueAtDecision(
            $connection,
            $operationId,
            $rowVersion,
            $decision->observedAt,
        )) {
            return null;
        }

        if ($status === OperationStatus::RetryWait) {
            $pending = $this->stateMachine->transition(
                $status,
                $effect,
                OperationStatus::Pending,
                EffectState::NotStarted,
                $operation->max_remote_writes,
            );
            $pendingRowVersion = $rowVersion + 1;
            $updated = $connection->table('integration_operations')
                ->where('id', $operationId->value)
                ->where('row_version', $rowVersion)
                ->where('status', OperationStatus::RetryWait->value)
                ->update([
                    'status' => OperationStatus::Pending->value,
                    'disposition' => OperationStatus::Pending->disposition()->value,
                    'next_attempt_at' => null,
                    'row_version' => $pendingRowVersion,
                    'updated_at' => $decision->observedAt,
                ]);

            if ($updated !== 1) {
                throw new OperationConcurrencyViolation;
            }

            $this->transitions->record(
                $connection,
                $operationId,
                $pending,
                $rowVersion,
                $pendingRowVersion,
                'retry_became_due',
                occurredAt: $decision->observedAt,
            );
            $status = OperationStatus::Pending;
            $effect = EffectState::NotStarted;
            $rowVersion = $pendingRowVersion;
        }

        $purpose = $status === OperationStatus::Uncertain
            ? LeasePurpose::Reconcile
            : LeasePurpose::Execute;
        $targetStatus = $purpose === LeasePurpose::Execute
            ? OperationStatus::Processing
            : OperationStatus::Reconciling;
        $transition = $this->stateMachine->transition(
            $status,
            $effect,
            $targetStatus,
            $effect,
            $operation->max_remote_writes,
        );
        $attemptId = $this->ulids->generate();
        $attemptNumber = ((int) $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->max('attempt_no')) + 1;
        $nextRowVersion = $rowVersion + 1;
        $updates = [
            'status' => $targetStatus->value,
            'disposition' => $targetStatus->disposition()->value,
            'row_version' => $nextRowVersion,
            'lease_owner' => $owner,
            'lease_token_sha256' => $tokenSha256,
            'lease_acquired_at' => $decision->observedAt,
            'lease_heartbeat_at' => $decision->observedAt,
            'lease_expires_at' => $decision->deadline,
            'active_attempt_id' => $attemptId->value,
            'last_attempt_id' => $attemptId->value,
            'updated_at' => $decision->observedAt,
        ];

        if ($purpose === LeasePurpose::Execute) {
            $updates['attempts'] = $operation->attempts + 1;
        } else {
            $updates['reconcile_attempts'] = $operation->reconcile_attempts + 1;
        }

        $connection->table('integration_operation_attempts')->insert([
            'id' => $attemptId->value,
            'operation_id' => $operationId->value,
            'attempt_no' => $attemptNumber,
            'mode' => $purpose->value,
            'effect_state_before' => $effect->value,
            'started_at' => $decision->observedAt,
            'worker_identity' => $owner,
            'lease_token_sha256' => $tokenSha256,
        ]);

        $updated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('row_version', $rowVersion)
            ->where('status', $status->value)
            ->whereNull('lease_token_sha256')
            ->whereNull('active_attempt_id')
            ->update($updates);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $this->transitions->record(
            $connection,
            $operationId,
            $transition,
            $rowVersion,
            $nextRowVersion,
            $purpose === LeasePurpose::Execute ? 'execution_claimed' : 'reconciliation_claimed',
            occurredAt: $decision->observedAt,
        );

        return new LeaseClaim(
            $operationId,
            IntegrationScope::of($identity->provider, $identity->connection_key),
            $purpose,
            $owner,
            $token,
            $nextRowVersion,
        );
    }

    /** @param array<string, int> $transactionBaseline */
    private function recoverOne(
        Connection $connection,
        LeaseRecoveryCursor $candidate,
        array $transactionBaseline,
    ): LeaseRecoveryEntryOutcome {
        $pendingQuarantine = null;

        try {
            $outcome = $connection->transaction(function () use ($connection, $candidate, &$pendingQuarantine): LeaseRecoveryEntryOutcome {
                $pendingQuarantine = null;
                $operationId = $candidate->operationId;
                $identity = $this->operationIdentity($operationId);

                if ($identity === null) {
                    return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
                }

                if (! $this->identityMatchesScope($identity, $candidate->scope)) {
                    return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
                }

                $intent = $this->lockIntentForOperation($connection, $identity);

                $operation = $connection->table('integration_operations')
                    ->where('id', $operationId->value)
                    ->whereIn('status', [OperationStatus::Processing->value, OperationStatus::Reconciling->value])
                    ->lockForUpdate()
                    ->first();

                if (! $operation instanceof stdClass) {
                    return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
                }

                if (! $this->operationMatchesScope($operation, $candidate->scope)) {
                    return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
                }

                if (! $this->operationMatchesIdentity($operation, $identity, $operationId)
                    || ! is_string($operation->status ?? null)
                    || ! is_string($operation->effect_state ?? null)
                    || ! is_int($operation->max_remote_writes ?? null)
                    || ! is_int($operation->row_version ?? null)
                    || ! is_string($operation->active_attempt_id ?? null)
                    || ! is_string($operation->last_attempt_id ?? null)
                    || ! is_string($operation->lease_owner ?? null)
                    || ! is_string($operation->lease_token_sha256 ?? null)) {
                    return $this->deferredRecoveryOutcome(
                        $candidate,
                        'integrity_operation_lifecycle',
                    );
                }

                $fromStatus = OperationStatus::tryFrom($operation->status);
                $fromEffect = EffectState::tryFrom($operation->effect_state);
                $currentIntentMatches = $intent instanceof stdClass
                    && $this->intentPointsToOperation($intent, $operation, $operationId);

                if ($fromStatus === null || $fromEffect === null) {
                    return $this->deferredRecoveryOutcome(
                        $candidate,
                        'integrity_operation_lifecycle',
                    );
                }

                $purpose = $fromStatus === OperationStatus::Processing
                    ? LeasePurpose::Execute
                    : LeasePurpose::Reconcile;
                $pointedAttempt = $connection->table('integration_operation_attempts')
                    ->where('id', $operation->active_attempt_id)
                    ->where('operation_id', $operationId->value)
                    ->lockForUpdate()
                    ->first();
                $openAttempts = $connection->table('integration_operation_attempts')
                    ->where('operation_id', $operationId->value)
                    ->whereNull('finished_at')
                    ->orderBy('attempt_no')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit(2)
                    ->get();
                $attempt = $openAttempts->count() === 1 ? $openAttempts->first() : null;
                $result = $connection->table('integration_operation_results')
                    ->where('operation_id', $operationId->value)
                    ->lockForUpdate()
                    ->first();
                $decision = $this->databaseDecision(
                    $connection,
                    $this->reconciliationDelaySeconds(),
                );

                if (! $this->persistedLeaseIsExpiredAtDecision(
                    $connection,
                    $operationId,
                    $fromStatus,
                    $operation,
                    $decision->observedAt,
                )) {
                    return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
                }

                if (! $currentIntentMatches) {
                    return $this->appendDeferredObservation(
                        $connection,
                        $candidate,
                        $operation,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        new LeaseRecoveryIncident(
                            $operationId,
                            $candidate->scope,
                            'integrity_current_intent_mismatch',
                            false,
                        ),
                    );
                }

                if (! $this->intentIdentityFieldsMatchOperation($intent, $operation)) {
                    $pendingQuarantine = new LeaseRecoveryIncident(
                        $operationId,
                        $candidate->scope,
                        'integrity_intent_identity_mismatch',
                        $attempt instanceof stdClass,
                    );

                    if (! $attempt instanceof stdClass) {
                        return $this->appendDeferredObservation(
                            $connection,
                            $candidate,
                            $operation,
                            $fromStatus,
                            $fromEffect,
                            $decision,
                            $pendingQuarantine,
                        );
                    }

                    return $this->quarantineExpiredLease(
                        $connection,
                        $operation,
                        $attempt,
                        $operationId,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        $pendingQuarantine,
                    );
                }

                if (! $this->lastAttemptPointsToMaximum($connection, $operation, $operationId)) {
                    $pendingQuarantine = new LeaseRecoveryIncident(
                        $operationId,
                        $candidate->scope,
                        'integrity_last_attempt_pointer',
                        $attempt instanceof stdClass,
                    );

                    if (! $attempt instanceof stdClass) {
                        return $this->appendDeferredObservation(
                            $connection,
                            $candidate,
                            $operation,
                            $fromStatus,
                            $fromEffect,
                            $decision,
                            new LeaseRecoveryIncident(
                                $operationId,
                                $candidate->scope,
                                'integrity_last_attempt_pointer',
                                false,
                            ),
                        );
                    }

                    return $this->quarantineExpiredLease(
                        $connection,
                        $operation,
                        $attempt,
                        $operationId,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        $pendingQuarantine,
                    );
                }

                if (! $attempt instanceof stdClass || $attempt->finished_at !== null) {
                    $incident = new LeaseRecoveryIncident(
                        $operationId,
                        $candidate->scope,
                        $result === null
                            ? 'integrity_active_attempt_unverifiable'
                            : 'integrity_unexpected_result',
                        $pointedAttempt instanceof stdClass
                            && is_string($pointedAttempt->finished_at ?? null),
                    );

                    if ($incident->quarantined && $pointedAttempt instanceof stdClass) {
                        $pendingQuarantine = $incident;

                        return $this->quarantineExpiredLease(
                            $connection,
                            $operation,
                            $pointedAttempt,
                            $operationId,
                            $fromStatus,
                            $fromEffect,
                            $decision,
                            $incident,
                        );
                    }

                    return $this->appendDeferredObservation(
                        $connection,
                        $candidate,
                        $operation,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        $incident,
                    );
                }

                if ($result !== null) {
                    $pendingQuarantine = new LeaseRecoveryIncident(
                        $operationId,
                        $candidate->scope,
                        'integrity_unexpected_result',
                        true,
                    );

                    return $this->quarantineExpiredLease(
                        $connection,
                        $operation,
                        $attempt,
                        $operationId,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        $pendingQuarantine,
                    );
                }

                if (! $this->activeAttemptCapabilityMatches($attempt, $operation, $purpose)) {
                    $pendingQuarantine = new LeaseRecoveryIncident(
                        $operationId,
                        $candidate->scope,
                        'integrity_active_attempt_capability',
                        true,
                    );

                    return $this->quarantineExpiredLease(
                        $connection,
                        $operation,
                        $attempt,
                        $operationId,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        $pendingQuarantine,
                    );
                }

                if (! $this->activeAttemptEffectMatches($attempt, $operation, $purpose, $fromEffect)) {
                    $pendingQuarantine = new LeaseRecoveryIncident(
                        $operationId,
                        $candidate->scope,
                        'integrity_attempt_effect_mismatch',
                        true,
                    );

                    return $this->quarantineExpiredLease(
                        $connection,
                        $operation,
                        $attempt,
                        $operationId,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        $pendingQuarantine,
                    );
                }

                $definition = $this->supportedDefinition($operation, false);

                if (! $definition instanceof OperationDefinition) {
                    return $this->appendDeferredObservation(
                        $connection,
                        $candidate,
                        $operation,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        new LeaseRecoveryIncident(
                            $operationId,
                            $candidate->scope,
                            'runtime_definition_unavailable',
                            false,
                        ),
                    );
                }

                if (! $this->managedMutationIdentityMatches($intent, $operation, $definition)) {
                    $pendingQuarantine = new LeaseRecoveryIncident(
                        $operationId,
                        $candidate->scope,
                        'integrity_managed_mutation_identity',
                        true,
                    );

                    return $this->quarantineExpiredLease(
                        $connection,
                        $operation,
                        $attempt,
                        $operationId,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        $pendingQuarantine,
                    );
                }

                if (! $this->definitions->runtimeBindingsAreAvailable($definition, $this->bindings)) {
                    return $this->appendDeferredObservation(
                        $connection,
                        $candidate,
                        $operation,
                        $fromStatus,
                        $fromEffect,
                        $decision,
                        new LeaseRecoveryIncident(
                            $operationId,
                            $candidate->scope,
                            'runtime_definition_unavailable',
                            false,
                        ),
                    );
                }

                return $this->recoverValidatedExpiredLease(
                    $connection,
                    $operation,
                    $operationId,
                    $fromStatus,
                    $fromEffect,
                    $purpose,
                    $decision,
                );
            }, 3);
        } catch (OperationConcurrencyViolation) {
            $outcome = new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
        } catch (OperationQuarantineUnavailable|QueryException) {
            if (! $pendingQuarantine instanceof LeaseRecoveryIncident) {
                $this->reportPersistenceFailure($transactionBaseline);

                throw new OperationPersistenceFailed;
            }

            try {
                $outcome = $this->persistRecoveryDeferral(
                    $connection,
                    $candidate,
                    new LeaseRecoveryIncident(
                        $pendingQuarantine->operationId,
                        $pendingQuarantine->scope,
                        $pendingQuarantine->safeCode,
                        false,
                    ),
                );
            } catch (Throwable) {
                $this->reportPersistenceFailure($transactionBaseline);

                throw new OperationPersistenceFailed;
            }
        } catch (Throwable) {
            $this->reportPersistenceFailure($transactionBaseline);

            throw new OperationPersistenceFailed;
        }

        return $outcome;
    }

    private function persistRecoveryDeferral(
        Connection $connection,
        LeaseRecoveryCursor $candidate,
        LeaseRecoveryIncident $incident,
    ): LeaseRecoveryEntryOutcome {
        return $connection->transaction(function () use ($connection, $candidate, $incident): LeaseRecoveryEntryOutcome {
            $operationId = $candidate->operationId;
            $identity = $this->operationIdentity($operationId);

            if ($identity === null || ! $this->identityMatchesScope($identity, $candidate->scope)) {
                throw new OperationPersistenceFailed;
            }

            $intent = $this->lockIntentIdentityForObservation($connection, $identity);

            if (! $intent instanceof stdClass) {
                throw new OperationPersistenceFailed;
            }

            $currentIntentMatches = $this->intentMatchesCurrentOperation($intent, $identity, $operationId);

            if ($incident->safeCode === 'integrity_current_intent_mismatch' && $currentIntentMatches) {
                return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
            }

            if (! $currentIntentMatches) {
                $incident = new LeaseRecoveryIncident(
                    $operationId,
                    $candidate->scope,
                    'integrity_current_intent_mismatch',
                    false,
                );
            }

            $operation = $connection->table('integration_operations')
                ->where('id', $operationId->value)
                ->whereIn('status', [OperationStatus::Processing->value, OperationStatus::Reconciling->value])
                ->lockForUpdate()
                ->first();

            if (! $operation instanceof stdClass) {
                return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
            }

            if (! $this->operationMatchesScope($operation, $candidate->scope)) {
                return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
            }

            if (! $this->operationMatchesIdentity($operation, $identity, $operationId)
                || ! is_string($operation->status ?? null)
                || ! is_string($operation->effect_state ?? null)
                || ! is_int($operation->row_version ?? null)
                || ! is_string($operation->lease_owner ?? null)
                || ! is_string($operation->lease_token_sha256 ?? null)
                || ! is_string($operation->active_attempt_id ?? null)
                || ! is_string($operation->last_attempt_id ?? null)) {
                throw new OperationPersistenceFailed;
            }

            $status = OperationStatus::tryFrom($operation->status);
            $effect = EffectState::tryFrom($operation->effect_state);

            if ($status === null || $effect === null) {
                throw new OperationPersistenceFailed;
            }

            $connection->table('integration_operation_attempts')
                ->where('operation_id', $operationId->value)
                ->whereIn('id', [$operation->active_attempt_id, $operation->last_attempt_id])
                ->lockForUpdate()
                ->get();
            $connection->table('integration_operation_attempts')
                ->where('operation_id', $operationId->value)
                ->whereNull('finished_at')
                ->lockForUpdate()
                ->limit(2)
                ->get();
            $decision = $this->databaseDecision(
                $connection,
                $this->reconciliationDelaySeconds(),
            );

            if (! $this->persistedLeaseIsExpiredAtDecision(
                $connection,
                $operationId,
                $status,
                $operation,
                $decision->observedAt,
            )) {
                return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
            }

            return $this->appendDeferredObservation(
                $connection,
                $candidate,
                $operation,
                $status,
                $effect,
                $decision,
                $incident,
            );
        }, 3);
    }

    private function appendDeferredObservation(
        Connection $connection,
        LeaseRecoveryCursor $candidate,
        stdClass $operation,
        OperationStatus $status,
        EffectState $effect,
        DatabaseLeaseDecision $decision,
        LeaseRecoveryIncident $incident,
    ): LeaseRecoveryEntryOutcome {
        if (! is_int($operation->row_version ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->active_attempt_id ?? null)) {
            throw new OperationPersistenceFailed;
        }

        $activeBackoff = $connection->table('integration_operation_attempts')
            ->where('operation_id', $candidate->operationId->value)
            ->where('mode', 'recovery')
            ->where('safe_outcome_category', 'deferred')
            ->where('retry_after_at', '>', $decision->observedAt)
            ->exists();

        if ($activeBackoff) {
            return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Skipped);
        }

        $attemptNumber = ((int) $connection->table('integration_operation_attempts')
            ->where('operation_id', $candidate->operationId->value)
            ->max('attempt_no')) + 1;
        $deferredAttemptId = $this->ulids->generate()->value;
        $connection->table('integration_operation_attempts')->insert([
            'id' => $deferredAttemptId,
            'operation_id' => $candidate->operationId->value,
            'attempt_no' => $attemptNumber,
            'mode' => 'recovery',
            'safe_outcome_category' => 'deferred',
            'effect_state_before' => $effect->value,
            'effect_state_after' => $effect->value,
            'started_at' => $decision->observedAt,
            'finished_at' => $decision->observedAt,
            'retry_after_at' => $decision->deadline,
            'error_category' => str_starts_with($incident->safeCode, 'runtime_') ? 'runtime' : 'integrity',
            'error_code' => $incident->safeCode,
            'safe_metadata' => json_encode([
                'source' => 'lease_recovery',
                'incident_code' => $incident->safeCode,
            ], JSON_THROW_ON_ERROR),
            'worker_identity' => 'kernel-recovery',
        ]);

        $updated = $connection->table('integration_operations')
            ->where('id', $candidate->operationId->value)
            ->where('row_version', $operation->row_version)
            ->where('status', $status->value)
            ->where('effect_state', $effect->value)
            ->where('lease_owner', $operation->lease_owner)
            ->where('lease_token_sha256', $operation->lease_token_sha256)
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->where('last_attempt_id', $operation->last_attempt_id)
            ->where('lease_expires_at', '<=', $decision->observedAt)
            ->update([
                'last_attempt_id' => $deferredAttemptId,
                'updated_at' => $decision->observedAt,
            ]);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }

        return new LeaseRecoveryEntryOutcome(
            LeaseRecoveryDisposition::Deferred,
            $incident,
        );
    }

    private function deferredRecoveryOutcome(
        LeaseRecoveryCursor $candidate,
        string $safeCode,
    ): LeaseRecoveryEntryOutcome {
        return new LeaseRecoveryEntryOutcome(
            LeaseRecoveryDisposition::Deferred,
            new LeaseRecoveryIncident(
                $candidate->operationId,
                $candidate->scope,
                $safeCode,
                false,
            ),
        );
    }

    private function notifyRecoveryIncidentBestEffort(LeaseRecoveryIncident $incident): bool
    {
        $this->assertRuntimeTransactionIsOutermost();
        $baseline = $this->database->transactionLevels();
        $failed = false;

        try {
            $this->incidents->notify($incident);
        } catch (Throwable) {
            $failed = true;
        }

        $levelsBeforeCleanup = $this->database->transactionLevels();

        if (array_filter($levelsBeforeCleanup, static fn (int $level): bool => $level !== 0) !== []) {
            $failed = true;
        }

        try {
            $this->database->restoreTransactionLevels($baseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        try {
            $this->assertRuntimeTransactionIsOutermost();
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        if ($failed) {
            $this->reportIncidentNotificationFailure($baseline);
        }

        return ! $failed;
    }

    /** @param array<string, int> $transactionBaseline */
    private function reportIncidentNotificationFailure(array $transactionBaseline): void
    {
        try {
            $this->database->restoreTransactionLevels($transactionBaseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        try {
            report(new LeaseRecoveryIncidentNotificationFailed);
        } catch (Throwable) {
        } finally {
            try {
                $this->database->restoreTransactionLevels($transactionBaseline);
            } catch (Throwable) {
                throw new OperationPersistenceFailed;
            }
        }
    }

    /** @param array<string, int> $transactionBaseline */
    private function reportPersistenceFailure(array $transactionBaseline): void
    {
        try {
            $this->database->restoreTransactionLevels($transactionBaseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        try {
            report(new OperationPersistenceFailed);
        } catch (Throwable) {
        } finally {
            try {
                $this->database->restoreTransactionLevels($transactionBaseline);
            } catch (Throwable) {
                throw new OperationPersistenceFailed;
            }
        }
    }

    private function quarantineExpiredLease(
        Connection $connection,
        stdClass $operation,
        stdClass $attempt,
        OperationId $operationId,
        OperationStatus $fromStatus,
        EffectState $fromEffect,
        DatabaseLeaseDecision $decision,
        LeaseRecoveryIncident $incident,
    ): LeaseRecoveryEntryOutcome {
        if (! is_int($operation->row_version ?? null)
            || ! is_int($operation->max_remote_writes ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            || ! is_string($operation->last_attempt_id ?? null)
            || ! is_string($attempt->id ?? null)
            || ! is_string($attempt->operation_id ?? null)
            || ! hash_equals($operation->active_attempt_id, $attempt->id)
            || ! hash_equals($operationId->value, $attempt->operation_id)) {
            throw new OperationQuarantineUnavailable;
        }

        $transition = $this->stateMachine->transition(
            $fromStatus,
            $fromEffect,
            OperationStatus::ManualReview,
            $fromEffect,
            $operation->max_remote_writes,
        );
        $nextRowVersion = $operation->row_version + 1;
        if ($attempt->finished_at === null) {
            $finalized = $connection->table('integration_operation_attempts')
                ->where('id', $attempt->id)
                ->where('operation_id', $operationId->value)
                ->whereNull('finished_at')
                ->update([
                    'safe_outcome_category' => 'integrity_quarantined',
                    'effect_state_after' => $fromEffect->value,
                    'finished_at' => $decision->observedAt,
                    'error_category' => 'integrity',
                    'error_code' => $incident->safeCode,
                ]);

            if ($finalized !== 1) {
                throw new OperationQuarantineUnavailable;
            }
        } elseif (! is_string($attempt->finished_at)) {
            throw new OperationQuarantineUnavailable;
        }

        $recoveryAttemptId = $this->ulids->generate();
        $attemptNumber = ((int) $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->max('attempt_no')) + 1;
        $connection->table('integration_operation_attempts')->insert([
            'id' => $recoveryAttemptId->value,
            'operation_id' => $operationId->value,
            'attempt_no' => $attemptNumber,
            'mode' => 'recovery',
            'safe_outcome_category' => 'integrity_quarantined',
            'effect_state_before' => $fromEffect->value,
            'effect_state_after' => $fromEffect->value,
            'started_at' => $decision->observedAt,
            'finished_at' => $decision->observedAt,
            'error_category' => 'integrity',
            'error_code' => $incident->safeCode,
            'worker_identity' => 'kernel-recovery',
        ]);

        $updated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('row_version', $operation->row_version)
            ->where('status', $fromStatus->value)
            ->where('lease_owner', $operation->lease_owner)
            ->where('lease_token_sha256', $operation->lease_token_sha256)
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->where('last_attempt_id', $operation->last_attempt_id)
            ->where('lease_expires_at', '<=', $decision->observedAt)
            ->update([
                'status' => OperationStatus::ManualReview->value,
                'disposition' => OperationStatus::ManualReview->disposition()->value,
                'row_version' => $nextRowVersion,
                'next_attempt_at' => null,
                'lease_owner' => null,
                'lease_token_sha256' => null,
                'lease_acquired_at' => null,
                'lease_heartbeat_at' => null,
                'lease_expires_at' => null,
                'active_attempt_id' => null,
                'last_attempt_id' => $recoveryAttemptId->value,
                'last_error_category' => 'integrity',
                'last_error_code' => $incident->safeCode,
                'updated_at' => $decision->observedAt,
            ]);

        if ($updated !== 1) {
            throw new OperationQuarantineUnavailable;
        }

        $this->transitions->record(
            $connection,
            $operationId,
            $transition,
            $operation->row_version,
            $nextRowVersion,
            $incident->safeCode,
            occurredAt: $decision->observedAt,
        );

        return new LeaseRecoveryEntryOutcome(
            LeaseRecoveryDisposition::Quarantined,
            $incident,
        );
    }

    private function recoverValidatedExpiredLease(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
        OperationStatus $fromStatus,
        EffectState $fromEffect,
        LeasePurpose $purpose,
        DatabaseLeaseDecision $decision,
    ): LeaseRecoveryEntryOutcome {
        if (! is_int($operation->row_version ?? null)
            || ! is_int($operation->max_remote_writes ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            || ! is_string($operation->last_attempt_id ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $beforeBoundary = $fromStatus === OperationStatus::Processing
            && $operation->request_started_at === null
            && $fromEffect === EffectState::NotStarted;
        $toStatus = $beforeBoundary ? OperationStatus::Pending : OperationStatus::Uncertain;
        $toEffect = $beforeBoundary ? EffectState::NotStarted : $fromEffect;
        $transition = $this->stateMachine->transition(
            $fromStatus,
            $fromEffect,
            $toStatus,
            $toEffect,
            $operation->max_remote_writes,
        );
        $nextRowVersion = $operation->row_version + 1;
        $recoveryAttemptId = $this->ulids->generate();
        $attemptNumber = ((int) $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->max('attempt_no')) + 1;
        $finalized = $connection->table('integration_operation_attempts')
            ->where('id', $operation->active_attempt_id)
            ->where('operation_id', $operationId->value)
            ->where('mode', $purpose->value)
            ->where('worker_identity', $operation->lease_owner)
            ->where('lease_token_sha256', $operation->lease_token_sha256)
            ->whereNull('finished_at')
            ->update([
                'safe_outcome_category' => 'lease_expired',
                'effect_state_after' => $toEffect->value,
                'finished_at' => $decision->observedAt,
                'error_category' => 'lease',
                'error_code' => 'lease_expired',
            ]);

        if ($finalized !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $connection->table('integration_operation_attempts')->insert([
            'id' => $recoveryAttemptId->value,
            'operation_id' => $operationId->value,
            'attempt_no' => $attemptNumber,
            'mode' => 'recovery',
            'safe_outcome_category' => 'lease_recovered',
            'effect_state_before' => $fromEffect->value,
            'effect_state_after' => $toEffect->value,
            'started_at' => $decision->observedAt,
            'finished_at' => $decision->observedAt,
            'error_category' => 'lease',
            'error_code' => 'lease_expired',
            'worker_identity' => 'kernel-recovery',
        ]);

        $updated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('row_version', $operation->row_version)
            ->where('status', $fromStatus->value)
            ->where('lease_owner', $operation->lease_owner)
            ->where('lease_token_sha256', $operation->lease_token_sha256)
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->where('last_attempt_id', $operation->last_attempt_id)
            ->where('lease_expires_at', '<=', $decision->observedAt)
            ->update([
                'status' => $toStatus->value,
                'disposition' => $toStatus->disposition()->value,
                'effect_state' => $toEffect->value,
                'row_version' => $nextRowVersion,
                'next_attempt_at' => $toStatus === OperationStatus::Uncertain
                    ? $decision->deadline
                    : null,
                'lease_owner' => null,
                'lease_token_sha256' => null,
                'lease_acquired_at' => null,
                'lease_heartbeat_at' => null,
                'lease_expires_at' => null,
                'active_attempt_id' => null,
                'last_attempt_id' => $recoveryAttemptId->value,
                'updated_at' => $decision->observedAt,
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
            $beforeBoundary ? 'expired_execution_lease_before_boundary' : 'expired_lease_after_boundary',
            occurredAt: $decision->observedAt,
        );

        return new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Recovered);
    }

    private function operationIdentity(OperationId $operationId): ?stdClass
    {
        $operation = $this->database->connection()
            ->table('integration_operations')
            ->where('id', $operationId->value)
            ->first();

        return $operation instanceof stdClass ? $operation : null;
    }

    private function supportedDefinition(
        stdClass $operation,
        bool $requireRuntimeBindings = true,
    ): ?OperationDefinition {
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
            || $definition->maximumRemoteWrites !== $operation->max_remote_writes
            || ($requireRuntimeBindings
                && ! $this->definitions->runtimeBindingsAreAvailable($definition, $this->bindings))) {
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

        return $this->writerFences->lockCurrentForClaim($connection, $scope, $operationType);
    }

    private function auditStaleWriterFenceAtClaim(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
        string $reason,
    ): null {
        if (! is_string($operation->status ?? null)
            || ! is_string($operation->effect_state ?? null)
            || ! is_int($operation->row_version ?? null)
            || ! is_int($operation->max_remote_writes ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $status = OperationStatus::tryFrom($operation->status);
        $effect = EffectState::tryFrom($operation->effect_state);

        if (! in_array($status, [OperationStatus::Pending, OperationStatus::RetryWait], true)
            || $effect !== EffectState::NotStarted) {
            return null;
        }

        $clock = $connection->selectOne('SELECT clock_timestamp() AS observed_at');

        if (! $clock instanceof stdClass || ! is_string($clock->observed_at ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        $rowVersion = $operation->row_version;
        $nextRowVersion = $rowVersion + 1;
        $updated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('status', $status->value)
            ->where('effect_state', EffectState::NotStarted->value)
            ->where('row_version', $rowVersion)
            ->whereNull('lease_owner')
            ->whereNull('lease_token_sha256')
            ->whereNull('active_attempt_id')
            ->whereNull('request_started_at')
            ->update([
                'status' => OperationStatus::ManualReview->value,
                'disposition' => OperationStatus::ManualReview->disposition()->value,
                'next_attempt_at' => null,
                'last_error_category' => 'writer_fence',
                'last_error_code' => $reason,
                'row_version' => $nextRowVersion,
                'updated_at' => $clock->observed_at,
            ]);

        if ($updated !== 1) {
            throw new OperationConcurrencyViolation;
        }

        $transition = $this->stateMachine->transition(
            $status,
            EffectState::NotStarted,
            OperationStatus::ManualReview,
            EffectState::NotStarted,
            $operation->max_remote_writes,
        );
        $this->transitions->record(
            $connection,
            $operationId,
            $transition,
            $rowVersion,
            $nextRowVersion,
            $reason,
            occurredAt: $clock->observed_at,
        );

        return null;
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

    private function lockIntentIdentityForObservation(Connection $connection, stdClass $identity): ?stdClass
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

    private function intentMatchesCurrentOperation(
        stdClass $intent,
        stdClass $identity,
        OperationId $operationId,
    ): bool {
        return is_string($intent->current_operation_id ?? null)
            && is_int($intent->current_generation ?? null)
            && is_int($identity->intent_generation ?? null)
            && hash_equals($operationId->value, $intent->current_operation_id)
            && $intent->current_generation === $identity->intent_generation;
    }

    private function operationMatchesIdentity(
        stdClass $operation,
        stdClass $identity,
        OperationId $operationId,
    ): bool {
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

    private function intentIdentityMatchesOperation(
        stdClass $intent,
        stdClass $operation,
        OperationDefinition $definition,
    ): bool {
        return $this->intentIdentityFieldsMatchOperation($intent, $operation)
            && $this->managedMutationIdentityMatches($intent, $operation, $definition);
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

    private function identityMatchesScope(stdClass $identity, IntegrationScope $scope): bool
    {
        return is_string($identity->provider ?? null)
            && is_string($identity->connection_key ?? null)
            && hash_equals($scope->provider->value, $identity->provider)
            && hash_equals($scope->connection->value, $identity->connection_key);
    }

    private function operationMatchesScope(stdClass $operation, IntegrationScope $scope): bool
    {
        return is_string($operation->provider ?? null)
            && is_string($operation->connection_key ?? null)
            && hash_equals($scope->provider->value, $operation->provider)
            && hash_equals($scope->connection->value, $operation->connection_key);
    }

    private function activeAttemptMatches(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
        LeasePurpose $purpose,
        EffectState $operationEffect,
    ): bool {
        $attempt = $this->lockActiveAttempt($connection, $operation, $operationId);

        return $attempt instanceof stdClass
            && $this->lastAttemptPointsToMaximum($connection, $operation, $operationId)
            && $this->activeAttemptCapabilityMatches($attempt, $operation, $purpose)
            && $this->activeAttemptEffectMatches($attempt, $operation, $purpose, $operationEffect);
    }

    private function lockActiveAttempt(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
    ): ?stdClass {
        if (! is_string($operation->active_attempt_id ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)) {
            return null;
        }

        $attempt = $connection->table('integration_operation_attempts')
            ->where('id', $operation->active_attempt_id)
            ->where('operation_id', $operationId->value)
            ->lockForUpdate()
            ->first();

        return $attempt instanceof stdClass ? $attempt : null;
    }

    private function activeAttemptCapabilityMatches(
        stdClass $attempt,
        stdClass $operation,
        LeasePurpose $purpose,
    ): bool {
        return $attempt->finished_at !== null
            || ! is_string($attempt->id ?? null)
            || ! is_string($attempt->mode ?? null)
            || ! is_string($attempt->worker_identity ?? null)
            || ! is_string($attempt->lease_token_sha256 ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->active_attempt_id ?? null)
            ? false
            : hash_equals($operation->active_attempt_id, $attempt->id)
                && hash_equals($purpose->value, $attempt->mode)
                && hash_equals($operation->lease_owner, $attempt->worker_identity)
                && hash_equals($operation->lease_token_sha256, $attempt->lease_token_sha256);
    }

    private function activeAttemptEffectMatches(
        stdClass $attempt,
        stdClass $operation,
        LeasePurpose $purpose,
        EffectState $operationEffect,
    ): bool {
        if (! is_string($attempt->effect_state_before ?? null)
            || ($attempt->effect_state_after ?? null) !== null) {
            return false;
        }

        $attemptRequestStartedAt = $attempt->request_started_at ?? null;
        $attemptResponseReceivedAt = $attempt->response_received_at ?? null;
        $operationRequestStartedAt = $operation->request_started_at ?? null;

        if (($attemptRequestStartedAt !== null && ! is_string($attemptRequestStartedAt))
            || ($attemptResponseReceivedAt !== null && ! is_string($attemptResponseReceivedAt))
            || ($operationRequestStartedAt !== null && ! is_string($operationRequestStartedAt))
            || ! is_int($operation->max_remote_writes ?? null)
            || ($attemptResponseReceivedAt !== null && $attemptRequestStartedAt === null)) {
            return false;
        }

        if ($purpose === LeasePurpose::Execute && $operation->max_remote_writes === 1
            && ((($operationRequestStartedAt === null) !== ($operationEffect === EffectState::NotStarted))
                || (($attemptRequestStartedAt === null) !== ($operationRequestStartedAt === null))
                || (is_string($attemptRequestStartedAt)
                    && is_string($operationRequestStartedAt)
                    && ! hash_equals($operationRequestStartedAt, $attemptRequestStartedAt)))) {
            return false;
        }

        $attemptEffect = EffectState::tryFrom($attempt->effect_state_before);

        if ($attemptEffect === null) {
            return false;
        }

        return $purpose === LeasePurpose::Execute
            ? $attemptEffect === EffectState::NotStarted
            : $attemptEffect === $operationEffect;
    }

    private function lastAttemptIsCoherentForClaim(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
    ): bool {
        if (($operation->active_attempt_id ?? null) !== null) {
            return false;
        }

        if (($operation->last_attempt_id ?? null) === null) {
            return is_int($operation->attempts ?? null)
                && is_int($operation->reconcile_attempts ?? null)
                && $operation->attempts === 0
                && $operation->reconcile_attempts === 0
                && ! $connection->table('integration_operation_attempts')
                    ->where('operation_id', $operationId->value)
                    ->exists();
        }

        if (! is_string($operation->last_attempt_id)) {
            return false;
        }

        $attempt = $connection->table('integration_operation_attempts')
            ->where('id', $operation->last_attempt_id)
            ->where('operation_id', $operationId->value)
            ->lockForUpdate()
            ->first();

        if (! $attempt instanceof stdClass
            || $attempt->finished_at === null
            || ! is_int($attempt->attempt_no ?? null)) {
            return false;
        }

        $lastAttemptNumber = $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->max('attempt_no');

        return is_int($lastAttemptNumber) && $attempt->attempt_no === $lastAttemptNumber;
    }

    private function lastAttemptPointsToMaximum(
        Connection $connection,
        stdClass $operation,
        OperationId $operationId,
    ): bool {
        if (! is_string($operation->last_attempt_id ?? null)) {
            return false;
        }

        $lastAttempt = $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->where('id', $operation->last_attempt_id)
            ->first();
        $maximumAttemptNumber = $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->max('attempt_no');

        return $lastAttempt instanceof stdClass
            && is_int($lastAttempt->attempt_no ?? null)
            && is_int($maximumAttemptNumber)
            && $lastAttempt->attempt_no === $maximumAttemptNumber;
    }

    private function claimIsDueAtDecision(
        Connection $connection,
        OperationId $operationId,
        int $rowVersion,
        string $observedAt,
    ): bool {
        return $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('row_version', $rowVersion)
            ->whereNull('lease_token_sha256')
            ->whereNull('active_attempt_id')
            ->where(function (Builder $eligible) use ($observedAt): void {
                $eligible->where(function (Builder $execute) use ($observedAt): void {
                    $execute->whereIn('status', [OperationStatus::Pending->value, OperationStatus::RetryWait->value])
                        ->where(function (Builder $due) use ($observedAt): void {
                            $due->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', $observedAt);
                        });
                })->orWhere(function (Builder $reconcile) use ($observedAt): void {
                    $reconcile->where('status', OperationStatus::Uncertain->value)
                        ->where('next_attempt_at', '<=', $observedAt);
                });
            })
            ->exists();
    }

    private function leaseMatchesAtDecision(
        Connection $connection,
        LeaseClaim $claim,
        OperationStatus $status,
        int $rowVersion,
        string $observedAt,
        string $tokenSha256,
    ): bool {
        return $connection->table('integration_operations')
            ->where('id', $claim->operationId->value)
            ->where('provider', $claim->scope->provider->value)
            ->where('connection_key', $claim->scope->connection->value)
            ->where('status', $status->value)
            ->where('lease_owner', $claim->owner)
            ->where('lease_token_sha256', $tokenSha256)
            ->where('row_version', $rowVersion)
            ->where('lease_expires_at', '>', $observedAt)
            ->exists();
    }

    private function persistedLeaseIsExpiredAtDecision(
        Connection $connection,
        OperationId $operationId,
        OperationStatus $status,
        stdClass $operation,
        string $observedAt,
    ): bool {
        if (! is_int($operation->row_version ?? null)
            || ! is_string($operation->lease_owner ?? null)
            || ! is_string($operation->lease_token_sha256 ?? null)
            || ! is_string($operation->active_attempt_id ?? null)) {
            return false;
        }

        return $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('status', $status->value)
            ->where('row_version', $operation->row_version)
            ->where('lease_owner', $operation->lease_owner)
            ->where('lease_token_sha256', $operation->lease_token_sha256)
            ->where('active_attempt_id', $operation->active_attempt_id)
            ->where('lease_expires_at', '<=', $observedAt)
            ->exists();
    }

    private function reconciliationDelaySeconds(): int
    {
        $seconds = $this->config->get('integration-operations.runtime.reconciliation_delay_seconds', 120);

        if (! is_int($seconds) || $seconds < 1 || $seconds > 604800) {
            throw new InvalidArgumentException('Integration operation reconciliation delay is invalid.');
        }

        return $seconds;
    }

    private function databaseDecision(Connection $connection, int $deadlineSeconds): DatabaseLeaseDecision
    {
        $clock = $connection->selectOne(
            <<<'SQL'
                WITH decision AS MATERIALIZED (
                    SELECT clock_timestamp() AS observed_at
                )
                SELECT observed_at, observed_at + (? * INTERVAL '1 second') AS deadline
                FROM decision
                SQL,
            [$deadlineSeconds],
        );

        if (! $clock instanceof stdClass
            || ! is_string($clock->observed_at ?? null)
            || ! is_string($clock->deadline ?? null)) {
            throw new OperationConcurrencyViolation;
        }

        return new DatabaseLeaseDecision($clock->observed_at, $clock->deadline);
    }

    private function assertOwner(string $owner): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $owner) !== 1) {
            throw new InvalidArgumentException('Lease owner is invalid.');
        }
    }

    private function assertRuntimeTransactionIsOutermost(): void
    {
        $this->database->assertNoForeignTransaction();

        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new RuntimeTransactionActive;
        }
    }
}
