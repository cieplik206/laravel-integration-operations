<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Exceptions\CrossConnectionTransaction;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\RuntimeTransactionActive;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\ConfigLocalReferenceTypeRegistry;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Runtime\DatabaseEffectBoundaryFactory;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationCoordinator;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationLeaseManager;
use Cieplik206\IntegrationOperations\Runtime\DatabaseTransitionRecorder;
use Cieplik206\IntegrationOperations\Runtime\DatabaseWriterFenceAuthority;
use Cieplik206\IntegrationOperations\Runtime\LeaseClaimHandle;
use Cieplik206\IntegrationOperations\Runtime\LeaseTimingPolicy;
use Cieplik206\IntegrationOperations\Runtime\OperationStateMachine;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\Tests\Support\HostileLeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingLeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Tests\Support\RollbackFailingConnection;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryCursor;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Assert;

it('claims, heartbeats, recovers, and steals an execution lease using database time and CAS tokens', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-read',
    ));
    $unsupportedRegistry = new DefinitionRegistry;
    $unsupportedRegistry->freeze(app(ContainerBindingInspector::class));
    $unsupportedManager = new DatabaseOperationLeaseManager(
        app(KernelDatabase::class),
        $unsupportedRegistry,
        app(ContainerBindingInspector::class),
        app(DatabaseWriterFenceAuthority::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        app(LeaseRecoveryIncidentNotifier::class),
        app(UlidFactory::class),
        app(LeaseTimingPolicy::class),
        app(Repository::class),
    );

    expect($unsupportedManager->claim($receipt->operationId, 'worker:unsupported'))->toBeNull()
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('pending');

    $claim = $manager->claim($receipt->operationId, 'worker:execute-1');

    expect($claim)->not->toBeNull();

    if ($claim === null) {
        throw new LogicException('The execution operation was not claimed.');
    }

    $claimed = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();

    expect($claim->purpose)->toBe(LeasePurpose::Execute)
        ->and($claim->rowVersion)->toBe(2)
        ->and($claimed?->status)->toBe('processing')
        ->and($claimed?->attempts)->toBe(1)
        ->and($claimed?->lease_token_sha256)->toBe(hash('sha256', $claim->token()))
        ->and($claimed?->lease_token_sha256)->not->toBe($claim->token())
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->where('lease_expires_at', '>', $connection->raw('CURRENT_TIMESTAMP'))->exists())->toBeTrue()
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->count())->toBe(1)
        ->and($manager->claim($receipt->operationId, 'worker:duplicate'))->toBeNull();

    $heartbeated = $manager->heartbeat($claim);

    expect($heartbeated)->not->toBeNull()
        ->and($heartbeated?->rowVersion)->toBe(3)
        ->and($manager->heartbeat($claim))->toBeNull();

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '1 minute'"),
        ]);

    expect($manager->recoverExpired(leaseScope('fixture_catalog'))->recovered)->toBe(1)
        ->and($manager->heartbeat($heartbeated ?? $claim))->toBeNull();

    $recovered = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();

    expect($recovered?->status)->toBe('pending')
        ->and($recovered?->effect_state)->toBe('not_started')
        ->and($recovered?->lease_token_sha256)->toBeNull()
        ->and($recovered?->row_version)->toBe(4)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->count())->toBe(2)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->whereNull('finished_at')->doesntExist())->toBeTrue();

    $stolen = $manager->claim($receipt->operationId, 'worker:execute-2');

    expect($stolen)->not->toBeNull()
        ->and($stolen?->rowVersion)->toBe(5)
        ->and($stolen?->token())->not->toBe($claim->token())
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('attempts'))->toBe(2)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->orderBy('attempt_no')->pluck('attempt_no')->all())->toBe([1, 2, 3]);
});

it('recovers every post-boundary or reconciliation expiry to uncertain without permitting another write', function (): void {
    [$connection, $manager, $coordinator, , $boundaryFactory] = prepareLeasePostgresRuntime('fixture_dispatch', OwnerMode::On);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_dispatch',
        'fixture_dispatch.message.deliver',
        'lease-write',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:write-1');

    expect($claim?->purpose)->toBe(LeasePurpose::Execute);

    $boundary = $boundaryFactory->make(new LeaseClaimHandle(
        $claim ?? throw new LogicException('Missing write claim.'),
    ));
    $boundary->open();
    expireLease($connection, $receipt->operationId);

    expect($manager->recoverExpired(leaseScope('fixture_dispatch'))->recovered)->toBe(1)
        ->and($manager->heartbeat($claim))->toBeNull();

    $uncertain = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();

    expect($uncertain?->status)->toBe('uncertain')
        ->and($uncertain?->effect_state)->toBe('possibly_applied')
        ->and($uncertain?->request_started_at)->not->toBeNull()
        ->and($uncertain?->next_attempt_at)->not->toBeNull()
        ->and($uncertain?->lease_token_sha256)->toBeNull()
        ->and($manager->claim($receipt->operationId, 'worker:too-early'))->toBeNull();

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update(['next_attempt_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
    $reconciliation = $manager->claim($receipt->operationId, 'worker:reconcile-1');

    expect($reconciliation?->purpose)->toBe(LeasePurpose::Reconcile)
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('reconciling');

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '1 minute'"),
        ]);

    expect($manager->recoverExpired(leaseScope('fixture_dispatch'))->recovered)->toBe(1)
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('uncertain')
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('effect_state'))->toBe('possibly_applied')
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->where('mode', 'recovery')->count())->toBe(2);
});

it('rejects claim heartbeat and recovery inside kernel or foreign transactions without mutation', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-transaction-gate',
    ));
    $beforeClaim = leasePersistenceFingerprint($connection, $receipt->operationId);

    $connection->beginTransaction();

    try {
        expect(fn () => $manager->claim($receipt->operationId, 'worker:nested'))
            ->toThrow(RuntimeTransactionActive::class);
    } finally {
        $connection->rollBack();
    }

    expect(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($beforeClaim);

    $claim = $manager->claim($receipt->operationId, 'worker:transaction-gate')
        ?? throw new LogicException('Missing transaction-gate lease claim.');
    $afterClaim = leasePersistenceFingerprint($connection, $receipt->operationId);
    $connection->beginTransaction();

    try {
        expect(fn () => $manager->heartbeat($claim))->toThrow(RuntimeTransactionActive::class)
            ->and(fn () => $manager->recoverExpired(leaseScope('fixture_catalog')))->toThrow(RuntimeTransactionActive::class);
    } finally {
        $connection->rollBack();
    }

    expect(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($afterClaim);

    /** @var DatabaseManager $database */
    $database = app('db');
    $configuration = leasePostgresConfiguration();
    config()->set('database.connections.integration_operations_lease_foreign', $configuration);
    $database->purge('integration_operations_lease_foreign');
    $foreign = $database->connection('integration_operations_lease_foreign');
    $foreign->beginTransaction();

    try {
        expect(fn () => $manager->claim($receipt->operationId, 'worker:foreign'))
            ->toThrow(CrossConnectionTransaction::class)
            ->and(fn () => $manager->heartbeat($claim))
            ->toThrow(CrossConnectionTransaction::class)
            ->and(fn () => $manager->recoverExpired(leaseScope('fixture_catalog')))
            ->toThrow(CrossConnectionTransaction::class);
    } finally {
        $foreign->rollBack();
        $database->purge('integration_operations_lease_foreign');
    }

    expect(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($afterClaim);
});

it('fails closed for a corrupt current-intent pointer and durably defers expired recovery', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-corrupt-intent',
    ));
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    if (! $operation instanceof stdClass || ! is_string($operation->intent_id ?? null)) {
        throw new LogicException('Missing accepted operation intent identity.');
    }

    $connection->table('integration_operation_intents')
        ->where('id', $operation->intent_id)
        ->update([
            'current_generation' => 2,
            'updated_at' => $connection->raw('clock_timestamp()'),
        ]);
    $before = leasePersistenceFingerprint($connection, $receipt->operationId);

    expect($manager->claim($receipt->operationId, 'worker:corrupt-intent'))->toBeNull()
        ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($before);

    $connection->table('integration_operation_intents')
        ->where('id', $operation->intent_id)
        ->update([
            'current_generation' => 1,
            'updated_at' => $connection->raw('clock_timestamp()'),
        ]);
    $claim = $manager->claim($receipt->operationId, 'worker:pointer-runtime')
        ?? throw new LogicException('Missing pointer-runtime lease claim.');
    $connection->table('integration_operation_intents')
        ->where('id', $operation->intent_id)
        ->update([
            'current_generation' => 2,
            'updated_at' => $connection->raw('clock_timestamp()'),
        ]);
    $beforeHeartbeat = leasePersistenceFingerprint($connection, $receipt->operationId);

    expect($manager->heartbeat($claim))->toBeNull()
        ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($beforeHeartbeat);

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '1 minute'"),
        ]);
    $beforeRecovery = leasePersistenceFingerprint($connection, $receipt->operationId);
    $beforeRecoveryOperation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $beforeTransitionCount = $connection->table('integration_operation_transitions')
        ->where('operation_id', $receipt->operationId->value)
        ->count();
    $batch = $manager->recoverExpired(leaseScope('fixture_catalog'));
    $deferredOperation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $deferredAttempt = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->orderByDesc('attempt_no')
        ->first();

    expect($batch->recovered)->toBe(0)
        ->and($batch->deferred)->toBe(1)
        ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->not->toBe($beforeRecovery)
        ->and($deferredOperation?->status)->toBe('processing')
        ->and($deferredOperation?->row_version)->toBe($beforeRecoveryOperation?->row_version)
        ->and($deferredOperation?->active_attempt_id)->toBe($beforeRecoveryOperation?->active_attempt_id)
        ->and($deferredOperation?->last_attempt_id)->toBe($deferredAttempt?->id)
        ->and($deferredAttempt?->mode)->toBe('recovery')
        ->and($deferredAttempt?->safe_outcome_category)->toBe('deferred')
        ->and($deferredAttempt?->error_code)->toBe('integrity_current_intent_mismatch')
        ->and($deferredAttempt?->retry_after_at)->not->toBeNull()
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $receipt->operationId->value)->count())->toBe($beforeTransitionCount)
        ->and($incidents->incidents)->toHaveCount(1)
        ->and($incidents->incidents[0]->safeCode)->toBe('integrity_current_intent_mismatch')
        ->and($manager->recoverExpired(leaseScope('fixture_catalog'))->scanned)->toBe(0);
});

it('uses durable backoff to prevent a deferred poison head from starving a healthy expired lease', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $poison = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-deferred-poison-head',
    ));
    $healthy = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-deferred-healthy-tail',
    ));
    $manager->claim($poison->operationId, 'worker:deferred-poison')
        ?? throw new LogicException('Missing deferred-poison lease claim.');
    $manager->claim($healthy->operationId, 'worker:deferred-healthy')
        ?? throw new LogicException('Missing deferred-healthy lease claim.');
    $poisonOperation = $connection->table('integration_operations')
        ->where('id', $poison->operationId->value)
        ->first();

    if (! $poisonOperation instanceof stdClass || ! is_string($poisonOperation->intent_id ?? null)) {
        throw new LogicException('Missing poison-head intent identity.');
    }

    $connection->table('integration_operation_intents')
        ->where('id', $poisonOperation->intent_id)
        ->update(['current_generation' => 2]);
    setExpiredLeaseAge($connection, $poison->operationId, 9);
    setExpiredLeaseAge($connection, $healthy->operationId, 6);
    $first = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 1);

    expect($first->deferred)->toBe(1)
        ->and($first->recovered)->toBe(0)
        ->and($first->scanned)->toBe(1)
        ->and($first->exhausted)->toBeFalse()
        ->and($connection->table('integration_operations')->where('id', $healthy->operationId->value)->value('status'))->toBe('processing');

    $second = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 1);

    expect($second->recovered)->toBe(1)
        ->and($second->scanned)->toBe(1)
        ->and($connection->table('integration_operations')->where('id', $poison->operationId->value)->value('status'))->toBe('processing')
        ->and($connection->table('integration_operations')->where('id', $healthy->operationId->value)->value('status'))->toBe('pending');
});

it('rejects stale or forged heartbeat capabilities and never binds a raw token to SQL', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-heartbeat-negative',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:heartbeat')
        ?? throw new LogicException('Missing heartbeat-negative lease claim.');
    $persistedDigest = hash('sha256', $claim->token());
    $capturedQueries = [];
    $connection->listen(function (QueryExecuted $query) use (&$capturedQueries): void {
        $capturedQueries[] = [$query->sql, $query->bindings];
    });
    $baseline = leasePersistenceFingerprint($connection, $receipt->operationId);
    $forgeries = [
        new LeaseClaim(
            $claim->operationId,
            IntegrationScope::of('fixture_catalog', 'tenant:other'),
            $claim->purpose,
            $claim->owner,
            $claim->token(),
            $claim->rowVersion,
        ),
        new LeaseClaim(
            $claim->operationId,
            $claim->scope,
            $claim->purpose,
            'worker:other',
            $claim->token(),
            $claim->rowVersion,
        ),
        new LeaseClaim(
            $claim->operationId,
            $claim->scope,
            $claim->purpose,
            $claim->owner,
            str_repeat('a', 64),
            $claim->rowVersion,
        ),
        new LeaseClaim(
            $claim->operationId,
            $claim->scope,
            $claim->purpose,
            $claim->owner,
            $persistedDigest,
            $claim->rowVersion,
        ),
        new LeaseClaim(
            $claim->operationId,
            $claim->scope,
            $claim->purpose,
            $claim->owner,
            $claim->token(),
            $claim->rowVersion + 1,
        ),
        new LeaseClaim(
            $claim->operationId,
            $claim->scope,
            LeasePurpose::Reconcile,
            $claim->owner,
            $claim->token(),
            $claim->rowVersion,
        ),
    ];

    foreach ($forgeries as $forgery) {
        expect($manager->heartbeat($forgery))->toBeNull()
            ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($baseline);
    }

    $queryTrace = json_encode($capturedQueries, JSON_THROW_ON_ERROR);

    expect($queryTrace)->not->toContain($claim->token())
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('lease_token_sha256'))
        ->toBe($persistedDigest)
        ->not->toBe($claim->token());

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '1 minute'"),
        ]);
    $expired = leasePersistenceFingerprint($connection, $receipt->operationId);

    expect($manager->heartbeat($claim))->toBeNull()
        ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($expired);
});

it('sanitizes persistence failures during pre-transaction claim identity lookup', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-claim-lookup-failure',
    ));
    $sentinel = 'sensitive-claim-query-sentinel';
    $connection->beforeExecuting(static function () use ($connection, $sentinel): never {
        $connection->beginTransaction();

        throw new RuntimeException($sentinel);
    });

    try {
        $manager->claim($receipt->operationId, 'worker:claim-query-failure');
        throw new LogicException('Expected the claim identity lookup to fail.');
    } catch (OperationPersistenceFailed $failure) {
        expect((string) $failure)->not->toContain($sentinel)
            ->and($failure->getPrevious())->toBeNull()
            ->and($connection->transactionLevel())->toBe(0);
    }
});

it('sanitizes persistence failures during pre-transaction heartbeat identity lookup', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-heartbeat-lookup-failure',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:heartbeat-query-failure')
        ?? throw new LogicException('Missing heartbeat lookup failure lease claim.');
    $sentinel = 'sensitive-heartbeat-query-sentinel';
    $connection->beforeExecuting(static function () use ($sentinel): never {
        throw new RuntimeException($sentinel);
    });

    try {
        $manager->heartbeat($claim);
        throw new LogicException('Expected the heartbeat identity lookup to fail.');
    } catch (OperationPersistenceFailed $failure) {
        expect((string) $failure)->not->toContain($sentinel)
            ->and($failure->getPrevious())->toBeNull();
    }
});

it('sanitizes persistence failures during the initial expired-candidate scan', function (): void {
    [$connection, $manager] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $sentinel = 'sensitive-recovery-scan-sentinel';
    $connection->beforeExecuting(static function () use ($sentinel): never {
        throw new RuntimeException($sentinel);
    });

    try {
        $manager->recoverExpired(leaseScope('fixture_catalog'));
        throw new LogicException('Expected the recovery candidate scan to fail.');
    } catch (OperationPersistenceFailed $failure) {
        expect((string) $failure)->not->toContain($sentinel)
            ->and($failure->getPrevious())->toBeNull();
    }
});

it('rejects heartbeat and quarantines an expired nonterminal operation with an unexpected result', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-ghost-result',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:ghost-result')
        ?? throw new LogicException('Missing ghost-result lease claim.');
    insertGhostLeaseResult($connection, $receipt->operationId);
    $beforeHeartbeat = leasePersistenceFingerprint($connection, $receipt->operationId);

    expect($manager->heartbeat($claim))->toBeNull()
        ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($beforeHeartbeat);

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '1 minute'"),
        ]);
    $batch = $manager->recoverExpired(leaseScope('fixture_catalog'));
    $quarantined = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    expect($batch->quarantined)->toBe(1)
        ->and($batch->recovered)->toBe(0)
        ->and($quarantined?->status)->toBe('manual_review')
        ->and($quarantined?->effect_state)->toBe('not_started')
        ->and($quarantined?->active_attempt_id)->toBeNull()
        ->and($connection->table('integration_operation_results')->where('operation_id', $receipt->operationId->value)->count())->toBe(1)
        ->and($incidents->incidents)->toHaveCount(1)
        ->and($incidents->incidents[0]->safeCode)->toBe('integrity_unexpected_result')
        ->and($incidents->incidents[0]->quarantined)->toBeTrue();
});

it('continues keyset recovery after a quarantined head reaches the lifecycle action limit', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $corrupt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-recovery-head-corrupt',
    ));
    $healthy = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-recovery-head-healthy',
    ));
    $manager->claim($corrupt->operationId, 'worker:head-corrupt')
        ?? throw new LogicException('Missing corrupt head lease claim.');
    $manager->claim($healthy->operationId, 'worker:head-healthy')
        ?? throw new LogicException('Missing healthy lease claim.');
    $connection->table('integration_operations')
        ->where('id', $corrupt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '7 minutes'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '6 minutes'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '5 minutes'"),
        ]);
    $connection->table('integration_operations')
        ->where('id', $healthy->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '6 minutes'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '5 minutes'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '4 minutes'"),
        ]);
    insertGhostLeaseResult($connection, $corrupt->operationId);
    $first = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 2);

    expect($first->quarantined)->toBe(1)
        ->and($first->recovered)->toBe(0)
        ->and($first->exhausted)->toBeFalse()
        ->and($first->nextCursor)->not->toBeNull()
        ->and($connection->table('integration_operations')->where('id', $corrupt->operationId->value)->value('status'))
        ->toBe('manual_review')
        ->and($connection->table('integration_operations')->where('id', $healthy->operationId->value)->value('status'))
        ->toBe('processing');

    $second = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 2, $first->nextCursor);

    expect($second->recovered)->toBe(1)
        ->and($second->exhausted)->toBeTrue()
        ->and($connection->table('integration_operations')->where('id', $healthy->operationId->value)->value('status'))
        ->toBe('pending');
});

it('skips a benign expiry race and continues to the healthy tail without an incident', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $raced = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-benign-race-head',
    ));
    $healthy = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-benign-race-tail',
    ));
    $manager->claim($raced->operationId, 'worker:benign-race-head')
        ?? throw new LogicException('Missing benign-race-head lease claim.');
    $manager->claim($healthy->operationId, 'worker:benign-race-tail')
        ?? throw new LogicException('Missing benign-race-tail lease claim.');
    setExpiredLeaseAge($connection, $raced->operationId, 9);
    setExpiredLeaseAge($connection, $healthy->operationId, 6);
    /** @var DatabaseManager $database */
    $database = app('db');
    $configuration = leasePostgresConfiguration();
    config()->set('database.connections.integration_operations_lease_race_observer', $configuration);
    $database->purge('integration_operations_lease_race_observer');
    $observer = $database->connection('integration_operations_lease_race_observer');
    $raceInjected = false;
    $connection->listen(function (QueryExecuted $query) use ($observer, $raced, &$raceInjected): void {
        if ($raceInjected || ! str_contains($query->sql, 'recovery_backoff')) {
            return;
        }

        $raceInjected = true;
        $observer->table('integration_operations')
            ->where('id', $raced->operationId->value)
            ->update([
                'lease_acquired_at' => $observer->raw("clock_timestamp() - INTERVAL '2 seconds'"),
                'lease_heartbeat_at' => $observer->raw("clock_timestamp() - INTERVAL '1 second'"),
                'lease_expires_at' => $observer->raw("clock_timestamp() + INTERVAL '1 minute'"),
            ]);
    });

    try {
        $batch = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 2);

        expect($raceInjected)->toBeTrue()
            ->and($batch->scanned)->toBe(2)
            ->and($batch->skipped)->toBe(1)
            ->and($batch->recovered)->toBe(1)
            ->and($batch->quarantined)->toBe(0)
            ->and($batch->deferred)->toBe(0)
            ->and($connection->table('integration_operations')->where('id', $raced->operationId->value)->value('status'))->toBe('processing')
            ->and($connection->table('integration_operations')->where('id', $healthy->operationId->value)->value('status'))->toBe('pending')
            ->and($incidents->incidents)->toBe([]);
    } finally {
        $database->purge('integration_operations_lease_race_observer');
    }
});

it('filters a schema-bypassed malformed identity without starving a healthy expired lease', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $malformed = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-malformed-identity-head',
    ));
    $healthy = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-malformed-identity-tail',
    ));
    $manager->claim($malformed->operationId, 'worker:malformed-identity-head')
        ?? throw new LogicException('Missing malformed-identity-head lease claim.');
    $manager->claim($healthy->operationId, 'worker:malformed-identity-tail')
        ?? throw new LogicException('Missing malformed-identity-tail lease claim.');
    setExpiredLeaseAge($connection, $malformed->operationId, 9);
    setExpiredLeaseAge($connection, $healthy->operationId, 6);
    $connection->statement('ALTER TABLE integration_operations DROP CONSTRAINT io_operations_ulid_check');
    $connection->statement('ALTER TABLE integration_operations DROP CONSTRAINT io_operations_intent_identity_fk');
    $connection->statement('ALTER TABLE integration_operations DISABLE TRIGGER io_operations_identity_immutable');

    try {
        $connection->table('integration_operations')
            ->where('id', $malformed->operationId->value)
            ->update(['operation_type' => 'malformed_without_provider_segments']);
    } finally {
        $connection->statement('ALTER TABLE integration_operations ENABLE TRIGGER io_operations_identity_immutable');
    }

    $batch = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 2);

    expect($batch->scanned)->toBe(1)
        ->and($batch->recovered)->toBe(1)
        ->and($batch->deferred)->toBe(0)
        ->and($batch->quarantined)->toBe(0)
        ->and($batch->skipped)->toBe(0)
        ->and($batch->exhausted)->toBeTrue()
        ->and($connection->table('integration_operations')->where('id', $malformed->operationId->value)->value('status'))->toBe('processing')
        ->and($connection->table('integration_operations')->where('id', $healthy->operationId->value)->value('status'))->toBe('pending')
        ->and($incidents->incidents)->toBe([]);
});

it('rejects cross-scope cursors and never scans or exposes foreign-scope expired rows', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $configuredFences = config('integration-operations.writer_fences');

    if (! is_array($configuredFences)) {
        throw new LogicException('Missing writer-fence fixture configuration.');
    }

    config()->set('integration-operations.writer_fences', [
        ...$configuredFences,
        [
            'provider' => 'fixture_catalog',
            'connection' => 'tenant:foreign',
            'operation_type' => 'fixture_catalog.record.fetch',
            'generation' => 1,
            'owner_mode' => OwnerMode::ShadowRead->value,
            'cohort' => null,
        ],
    ]);
    $local = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-scope-local',
    ));
    $foreign = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-scope-foreign',
        'tenant:foreign',
    ));
    $manager->claim($local->operationId, 'worker:scope-local')
        ?? throw new LogicException('Missing local-scope lease claim.');
    $manager->claim($foreign->operationId, 'worker:scope-foreign')
        ?? throw new LogicException('Missing foreign-scope lease claim.');
    setExpiredLeaseAge($connection, $local->operationId, 6);
    setExpiredLeaseAge($connection, $foreign->operationId, 7);
    $foreignExpiry = $connection->table('integration_operations')
        ->where('id', $foreign->operationId->value)
        ->value('lease_expires_at');

    if (! is_string($foreignExpiry)) {
        throw new LogicException('Missing foreign-scope lease expiry.');
    }

    $foreignCursor = LeaseRecoveryCursor::fromDatabase(
        IntegrationScope::of('fixture_catalog', 'tenant:foreign'),
        $foreignExpiry,
        $foreign->operationId,
    );
    $foreignBefore = leasePersistenceFingerprint($connection, $foreign->operationId);

    expect(fn () => $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 1, $foreignCursor))
        ->toThrow(InvalidArgumentException::class)
        ->and(leasePersistenceFingerprint($connection, $foreign->operationId))->toBe($foreignBefore);

    $localBatch = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 1);

    expect($localBatch->scanned)->toBe(1)
        ->and($localBatch->recovered)->toBe(1)
        ->and($localBatch->nextCursor?->scope->equals(leaseScope('fixture_catalog')))->toBeTrue()
        ->and($localBatch->nextCursor?->operationId->equals($local->operationId))->toBeTrue()
        ->and($connection->table('integration_operations')->where('id', $foreign->operationId->value)->value('status'))->toBe('processing')
        ->and($incidents->incidents)->toBe([]);
});

it('validates both recovery bounds before querying persistence', function (): void {
    [, $manager] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);

    foreach ([[0, 1], [501, 501], [2, 1], [1, 5001]] as [$limit, $scanLimit]) {
        expect(fn () => $manager->recoverExpired(leaseScope('fixture_catalog'), $limit, $scanLimit))
            ->toThrow(InvalidArgumentException::class);
    }

    $empty = $manager->recoverExpired(leaseScope('fixture_catalog'), 500, 5000);

    expect($empty->scanned)->toBe(0)
        ->and($empty->exhausted)->toBeTrue()
        ->and($empty->nextCursor)->toBeNull();
});

it('marks an action-limited batch exhausted when its action is the exact final candidate', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-exhausted-exact-final',
    ));
    $manager->claim($receipt->operationId, 'worker:exhausted-final')
        ?? throw new LogicException('Missing exact-final lease claim.');
    expireLease($connection, $receipt->operationId);
    $batch = $manager->recoverExpired(leaseScope('fixture_catalog'), 1, 1);

    expect($batch->recovered)->toBe(1)
        ->and($batch->scanned)->toBe(1)
        ->and($batch->exhausted)->toBeTrue()
        ->and($batch->nextCursor?->operationId->equals($receipt->operationId))->toBeTrue();
});

it('commits every recovery decision before isolated incident callbacks and cleans callback transactions', function (): void {
    [$connection, $claimManager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $firstCorrupt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-notifier-first-corrupt',
    ));
    $secondCorrupt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-notifier-second-corrupt',
    ));
    $healthy = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-notifier-healthy',
    ));

    foreach ([$firstCorrupt, $secondCorrupt, $healthy] as $index => $receipt) {
        $claimManager->claim(
            $receipt->operationId,
            'worker:notifier-'.($index + 1),
        ) ?? throw new LogicException('Missing notifier isolation lease claim.');
        setExpiredLeaseAge($connection, $receipt->operationId, 9 - $index);
    }

    insertGhostLeaseResult($connection, $firstCorrupt->operationId);
    insertGhostLeaseResult($connection, $secondCorrupt->operationId);
    /** @var DatabaseManager $database */
    $database = app('db');
    $configuration = leasePostgresConfiguration();
    config()->set('database.connections.integration_operations_lease_notifier_observer', $configuration);
    config()->set('database.connections.integration_operations_lease_notifier_foreign', $configuration);
    $database->purge('integration_operations_lease_notifier_observer');
    $database->purge('integration_operations_lease_notifier_foreign');
    $observer = $database->connection('integration_operations_lease_notifier_observer');
    $foreign = $database->connection('integration_operations_lease_notifier_foreign');
    $notifier = new HostileLeaseRecoveryIncidentNotifier(
        $observer,
        [$connection, $foreign],
        [$firstCorrupt->operationId, $secondCorrupt->operationId, $healthy->operationId],
    );
    $manager = new DatabaseOperationLeaseManager(
        app(KernelDatabase::class),
        app(DefinitionRegistry::class),
        app(ContainerBindingInspector::class),
        app(DatabaseWriterFenceAuthority::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        $notifier,
        app(UlidFactory::class),
        app(LeaseTimingPolicy::class),
        app(Repository::class),
    );

    try {
        $batch = $manager->recoverExpired(leaseScope('fixture_catalog'), 3, 3);

        expect($batch->quarantined)->toBe(2)
            ->and($batch->recovered)->toBe(1)
            ->and($batch->notificationFailures)->toBe(1)
            ->and($notifier->calls)->toBe(2)
            ->and($notifier->observedStatuses)->toHaveCount(2)
            ->and($notifier->observedStatuses[0][$firstCorrupt->operationId->value] ?? null)->toBe('manual_review')
            ->and($notifier->observedStatuses[0][$secondCorrupt->operationId->value] ?? null)->toBe('manual_review')
            ->and($notifier->observedStatuses[0][$healthy->operationId->value] ?? null)->toBe('pending')
            ->and($connection->transactionLevel())->toBe(0)
            ->and($foreign->transactionLevel())->toBe(0)
            ->and($observer->table('integration_operations')->where('id', $healthy->operationId->value)->value('status'))->toBe('pending');
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        while ($foreign->transactionLevel() > 0) {
            $foreign->rollBack();
        }

        $database->purge('integration_operations_lease_notifier_observer');
        $database->purge('integration_operations_lease_notifier_foreign');
    }
});

it('cleans remaining callback connections and stops notifications after a sanitized cleanup failure', function (): void {
    [$connection, $claimManager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $first = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-cleanup-failure-first',
    ));
    $second = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-cleanup-failure-second',
    ));

    foreach ([$first, $second] as $index => $receipt) {
        $claimManager->claim($receipt->operationId, 'worker:cleanup-failure-'.($index + 1))
            ?? throw new LogicException('Missing cleanup-failure lease claim.');
        setExpiredLeaseAge($connection, $receipt->operationId, 8 - $index);
        insertGhostLeaseResult($connection, $receipt->operationId);
    }

    /** @var DatabaseManager $database */
    $database = app('db');
    $configuration = leasePostgresConfiguration();
    config()->set('database.connections.integration_operations_lease_cleanup_observer', $configuration);
    config()->set('database.connections.integration_operations_lease_cleanup_normal', $configuration);
    config()->set('database.connections.integration_operations_lease_cleanup_failing', [
        'driver' => 'rollback_failing',
        'database' => 'rollback-failing-fixture',
    ]);
    $database->purge('integration_operations_lease_cleanup_observer');
    $database->purge('integration_operations_lease_cleanup_normal');
    $database->purge('integration_operations_lease_cleanup_failing');
    $observer = $database->connection('integration_operations_lease_cleanup_observer');
    $failing = new RollbackFailingConnection($connection->getPdo());
    $database->extend(
        'rollback_failing',
        static fn (): RollbackFailingConnection => $failing,
    );
    $resolvedFailing = $database->connection('integration_operations_lease_cleanup_failing');
    $normal = $database->connection('integration_operations_lease_cleanup_normal');
    $notifier = new HostileLeaseRecoveryIncidentNotifier(
        $observer,
        [$resolvedFailing, $normal],
        [$first->operationId, $second->operationId],
    );
    $manager = new DatabaseOperationLeaseManager(
        app(KernelDatabase::class),
        app(DefinitionRegistry::class),
        app(ContainerBindingInspector::class),
        app(DatabaseWriterFenceAuthority::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        $notifier,
        app(UlidFactory::class),
        app(LeaseTimingPolicy::class),
        app(Repository::class),
    );

    try {
        try {
            $manager->recoverExpired(leaseScope('fixture_catalog'), 2, 2);
            throw new LogicException('Expected incident transaction cleanup to fail.');
        } catch (OperationPersistenceFailed $failure) {
            expect((string) $failure)->not->toContain('Sensitive rollback failure fixture')
                ->and($failure->getPrevious())->toBeNull();
        }

        expect($notifier->calls)->toBe(1)
            ->and($normal->transactionLevel())->toBe(0)
            ->and($observer->table('integration_operations')->whereIn('id', [
                $first->operationId->value,
                $second->operationId->value,
            ])->where('status', 'manual_review')->count())->toBe(2);
    } finally {
        if ($normal->transactionLevel() > 0) {
            $normal->rollBack(0);
        }

        $failing->resetAfterTest();
        $database->purge('integration_operations_lease_cleanup_observer');
        $database->purge('integration_operations_lease_cleanup_normal');
        $database->purge('integration_operations_lease_cleanup_failing');
    }
});

it('makes a committed claim and its attempt visible to an independent PostgreSQL connection before returning', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-visible',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:visible')
        ?? throw new LogicException('Missing visible lease claim.');

    /** @var DatabaseManager $database */
    $database = app('db');
    $configuration = leasePostgresConfiguration();
    config()->set('database.connections.integration_operations_lease_observer', $configuration);
    $database->purge('integration_operations_lease_observer');
    $observer = $database->connection('integration_operations_lease_observer');
    $visibleOperation = $observer->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    expect($visibleOperation?->status)->toBe('processing')
        ->and($visibleOperation?->row_version)->toBe($claim->rowVersion)
        ->and($visibleOperation?->lease_token_sha256)->toBe(hash('sha256', $claim->token()))
        ->and($visibleOperation?->lease_token_sha256)->not->toBe($claim->token())
        ->and($observer->table('integration_operation_attempts')
            ->where('operation_id', $receipt->operationId->value)
            ->whereNull('finished_at')
            ->where('lease_token_sha256', hash('sha256', $claim->token()))
            ->count())->toBe(1)
        ->and($observer->table('integration_operation_transitions')
            ->where('operation_id', $receipt->operationId->value)
            ->where('reason_code', 'execution_claimed')
            ->count())->toBe(1);

    $database->purge('integration_operations_lease_observer');

    expect($connection->transactionLevel())->toBe(0);
});

it('fails a new claim closed when required frozen provider bindings are rebound without constructing them', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-binding-claim',
    ));
    $before = leasePersistenceFingerprint($connection, $receipt->operationId);
    $originalBinding = captureLeaseProviderBinding();
    $constructionAttempts = FakeProviderExtensions::$constructionAttempts;

    try {
        app()->singleton(FakeProviderExtensions::class, stdClass::class);

        expect($manager->claim($receipt->operationId, 'worker:binding-rebound'))->toBeNull()
            ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($before)
            ->and(FakeProviderExtensions::$constructionAttempts)->toBe($constructionAttempts);
    } finally {
        restoreLeaseProviderBinding($originalBinding);
    }
});

it('durably defers an otherwise valid expired lease when its frozen definition tuple is unavailable', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    config()->set('integration-operations.runtime.reconciliation_delay_seconds', 1);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-missing-definition-recovery',
    ));
    $manager->claim($receipt->operationId, 'worker:missing-definition')
        ?? throw new LogicException('Missing definition-unavailable lease claim.');
    expireLease($connection, $receipt->operationId);
    $before = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $transitionCount = $connection->table('integration_operation_transitions')
        ->where('operation_id', $receipt->operationId->value)
        ->count();
    $emptyRegistry = new DefinitionRegistry;
    $emptyRegistry->freeze(app(ContainerBindingInspector::class));
    $unavailableManager = new DatabaseOperationLeaseManager(
        app(KernelDatabase::class),
        $emptyRegistry,
        app(ContainerBindingInspector::class),
        app(DatabaseWriterFenceAuthority::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        app(LeaseRecoveryIncidentNotifier::class),
        app(UlidFactory::class),
        app(LeaseTimingPolicy::class),
        app(Repository::class),
    );
    $batch = $unavailableManager->recoverExpired(leaseScope('fixture_catalog'));
    $deferred = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $observation = $connection->table('integration_operation_attempts')
        ->where('id', $deferred?->last_attempt_id)
        ->first();

    expect($batch->deferred)->toBe(1)
        ->and($deferred?->status)->toBe('processing')
        ->and($deferred?->row_version)->toBe($before?->row_version)
        ->and($deferred?->active_attempt_id)->toBe($before?->active_attempt_id)
        ->and($observation?->safe_outcome_category)->toBe('deferred')
        ->and($observation?->error_code)->toBe('runtime_definition_unavailable')
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $receipt->operationId->value)->count())->toBe($transitionCount)
        ->and($manager->recoverExpired(leaseScope('fixture_catalog'))->scanned)->toBe(0);

    sleep(2);

    expect($manager->recoverExpired(leaseScope('fixture_catalog'))->recovered)->toBe(1);
});

it('durably defers recovery for rebound required bindings then resumes after exact restoration and backoff', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    config()->set('integration-operations.runtime.reconciliation_delay_seconds', 1);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-binding-recovery',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:binding-recovery')
        ?? throw new LogicException('Missing binding-recovery lease claim.');
    expireLease($connection, $receipt->operationId);
    $activeAttemptId = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->value('active_attempt_id');
    $originalBinding = captureLeaseProviderBinding();
    $constructionAttempts = FakeProviderExtensions::$constructionAttempts;

    try {
        app()->singleton(FakeProviderExtensions::class, stdClass::class);
        $deferred = $manager->recoverExpired(leaseScope('fixture_catalog'));
        $operation = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();
        $observation = $connection->table('integration_operation_attempts')->where('id', $operation?->last_attempt_id)->first();

        expect($deferred->deferred)->toBe(1)
            ->and($operation?->status)->toBe('processing')
            ->and($operation?->active_attempt_id)->toBe($activeAttemptId)
            ->and($connection->table('integration_operation_attempts')->where('id', $activeAttemptId)->value('attempt_no'))->toBe(1)
            ->and($observation?->attempt_no)->toBe(2)
            ->and($observation?->error_code)->toBe('runtime_definition_unavailable')
            ->and(FakeProviderExtensions::$constructionAttempts)->toBe($constructionAttempts)
            ->and($incidents->incidents)->toHaveCount(1);

        restoreLeaseProviderBinding($originalBinding);

        expect($manager->recoverExpired(leaseScope('fixture_catalog'))->scanned)->toBe(0);
        sleep(2);

        expect($manager->recoverExpired(leaseScope('fixture_catalog'))->recovered)->toBe(1);
        $recovered = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();
        $lastAttempt = $connection->table('integration_operation_attempts')->where('id', $recovered?->last_attempt_id)->first();

        expect($recovered?->status)->toBe('pending')
            ->and($recovered?->active_attempt_id)->toBeNull()
            ->and($lastAttempt?->attempt_no)->toBe(3)
            ->and($connection->table('integration_operation_attempts')->where('id', $activeAttemptId)->whereNotNull('finished_at')->exists())->toBeTrue()
            ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->whereNull('finished_at')->doesntExist())->toBeTrue();
    } finally {
        restoreLeaseProviderBinding($originalBinding);
    }
});

it('quarantines kernel data corruption even when a required provider binding is rebound', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-binding-ghost-result',
    ));
    $manager->claim($receipt->operationId, 'worker:binding-ghost-result')
        ?? throw new LogicException('Missing binding-ghost-result lease claim.');
    expireLease($connection, $receipt->operationId);
    insertGhostLeaseResult($connection, $receipt->operationId);
    $originalBinding = captureLeaseProviderBinding();
    $constructionAttempts = FakeProviderExtensions::$constructionAttempts;

    try {
        app()->singleton(FakeProviderExtensions::class, stdClass::class);
        $batch = $manager->recoverExpired(leaseScope('fixture_catalog'));
        $operation = $connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->first();

        expect($batch->quarantined)->toBe(1)
            ->and($batch->deferred)->toBe(0)
            ->and($operation?->status)->toBe('manual_review')
            ->and($operation?->active_attempt_id)->toBeNull()
            ->and($connection->table('integration_operation_results')->where('operation_id', $receipt->operationId->value)->count())->toBe(1)
            ->and($incidents->incidents)->toHaveCount(1)
            ->and($incidents->incidents[0]->safeCode)->toBe('integrity_unexpected_result')
            ->and($incidents->incidents[0]->quarantined)->toBeTrue()
            ->and(FakeProviderExtensions::$constructionAttempts)->toBe($constructionAttempts);
    } finally {
        restoreLeaseProviderBinding($originalBinding);
    }
});

it('keeps an exact active lease heartbeat valid after a later provider binding rebind', function (): void {
    [$connection, $manager, $coordinator, , $boundaryFactory] = prepareLeasePostgresRuntime('fixture_dispatch', OwnerMode::On);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_dispatch',
        'fixture_dispatch.message.deliver',
        'lease-binding-heartbeat',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:binding-heartbeat')
        ?? throw new LogicException('Missing binding-heartbeat lease claim.');
    $claimHandle = new LeaseClaimHandle($claim);
    $boundaryFactory->make($claimHandle)->open();
    $originalBinding = captureLeaseProviderBinding();

    try {
        app()->singleton(FakeProviderExtensions::class, stdClass::class);
        $renewed = $manager->heartbeat($claimHandle->claim());

        expect($renewed?->rowVersion)->toBe($claim->rowVersion + 2)
            ->and($manager->claim($receipt->operationId, 'worker:second'))->toBeNull()
            ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('processing');
    } finally {
        restoreLeaseProviderBinding($originalBinding);
    }
});

it('quarantines a safely closable expired lease when its active capability is corrupted', function (string $corruption): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-corrupt-attempt-'.$corruption,
    ));
    $manager->claim($receipt->operationId, 'worker:corrupt-attempt')
        ?? throw new LogicException('Missing corrupt-attempt lease claim.');
    expireLease($connection, $receipt->operationId);
    $attempt = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->whereNull('finished_at')
        ->first();

    if (! $attempt instanceof stdClass || ! is_string($attempt->id ?? null)) {
        throw new LogicException('Missing open attempt for corruption checks.');
    }

    $mutation = match ($corruption) {
        'mode' => ['mode' => 'reconcile'],
        'owner' => ['worker_identity' => 'worker:forged'],
        'token' => ['lease_token_sha256' => str_repeat('a', 64)],
        'effect' => ['effect_state_before' => 'possibly_applied'],
        default => throw new LogicException('Unknown active-attempt corruption fixture.'),
    };
    mutateLeaseAttemptBypassingGuard($connection, $attempt->id, $mutation);
    $batch = $manager->recoverExpired(leaseScope('fixture_catalog'));
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    expect($batch->quarantined)->toBe(1)
        ->and($batch->deferred)->toBe(0)
        ->and($operation?->status)->toBe('manual_review')
        ->and($operation?->active_attempt_id)->toBeNull()
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->whereNull('finished_at')->doesntExist())->toBeTrue()
        ->and($incidents->incidents)->toHaveCount(1)
        ->and($incidents->incidents[0]->quarantined)->toBeTrue()
        ->and($incidents->incidents[0]->safeCode)->toBe(
            $corruption === 'effect'
                ? 'integrity_attempt_effect_mismatch'
                : 'integrity_active_attempt_capability',
        );
})->with(['mode', 'owner', 'token', 'effect']);

it('quarantines single-effect request evidence that lacks the durable operation boundary marker', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_dispatch', OwnerMode::On);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_dispatch',
        'fixture_dispatch.message.deliver',
        'lease-attempt-request-evidence',
    ));
    $manager->claim($receipt->operationId, 'worker:attempt-request-evidence')
        ?? throw new LogicException('Missing attempt-request-evidence lease claim.');
    expireLease($connection, $receipt->operationId);
    $attempt = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->whereNull('finished_at')
        ->first();

    if (! $attempt instanceof stdClass || ! is_string($attempt->id ?? null)) {
        throw new LogicException('Missing open attempt for request-evidence corruption.');
    }

    mutateLeaseAttemptBypassingGuard($connection, $attempt->id, [
        'request_started_at' => $connection->raw('clock_timestamp()'),
    ]);
    $batch = $manager->recoverExpired(leaseScope('fixture_dispatch'));
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    expect($batch->quarantined)->toBe(1)
        ->and($batch->recovered)->toBe(0)
        ->and($operation?->status)->toBe('manual_review')
        ->and($operation?->effect_state)->toBe('not_started')
        ->and($operation?->request_started_at)->toBeNull()
        ->and($incidents->incidents)->toHaveCount(1)
        ->and($incidents->incidents[0]->safeCode)->toBe('integrity_attempt_effect_mismatch');
});

it('quarantines a single-effect operation marker and effect contradiction even when the database check is bypassed', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_dispatch', OwnerMode::On);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_dispatch',
        'fixture_dispatch.message.deliver',
        'lease-operation-boundary-contradiction',
    ));
    $manager->claim($receipt->operationId, 'worker:operation-boundary-contradiction')
        ?? throw new LogicException('Missing operation-boundary-contradiction lease claim.');
    $connection->statement('ALTER TABLE integration_operations DROP CONSTRAINT io_operations_lifecycle_check');
    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update(['effect_state' => 'possibly_applied']);
    expireLease($connection, $receipt->operationId);
    $batch = $manager->recoverExpired(leaseScope('fixture_dispatch'));
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    expect($batch->quarantined)->toBe(1)
        ->and($batch->recovered)->toBe(0)
        ->and($operation?->status)->toBe('manual_review')
        ->and($operation?->effect_state)->toBe('possibly_applied')
        ->and($operation?->request_started_at)->toBeNull()
        ->and($incidents->incidents)->toHaveCount(1)
        ->and($incidents->incidents[0]->safeCode)->toBe('integrity_attempt_effect_mismatch');
});

it('quarantines an expired managed mutation whose locked intent local type contradicts the frozen definition', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_dispatch', OwnerMode::On);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_dispatch',
        'fixture_dispatch.message.deliver',
        'lease-intent-local-type',
    ));
    $manager->claim($receipt->operationId, 'worker:intent-local-type')
        ?? throw new LogicException('Missing managed mutation identity lease.');
    $intentId = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->value('intent_id');

    if (! is_string($intentId)) {
        throw new LogicException('Missing managed mutation intent identity.');
    }

    mutateLeaseIntentLocalType($connection, $intentId, 'foreign_resource');
    expireLease($connection, $receipt->operationId);
    $batch = $manager->recoverExpired(leaseScope('fixture_dispatch'));
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    expect($batch->quarantined)->toBe(1)
        ->and($batch->recovered)->toBe(0)
        ->and($operation?->status)->toBe('manual_review')
        ->and($operation?->active_attempt_id)->toBeNull()
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->whereNull('finished_at')->doesntExist())->toBeTrue()
        ->and($incidents->incidents)->toHaveCount(1)
        ->and($incidents->incidents[0]->safeCode)->toBe('integrity_managed_mutation_identity');
});

it('atomically quarantines an expired lease whose active pointer identifies a finished attempt', function (): void {
    [$connection, $manager, $coordinator, $incidents] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-finished-active-attempt',
    ));
    $manager->claim($receipt->operationId, 'worker:finished-active')
        ?? throw new LogicException('Missing finished-active lease claim.');
    expireLease($connection, $receipt->operationId);
    $attempt = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->whereNull('finished_at')
        ->first();

    if (! $attempt instanceof stdClass || ! is_string($attempt->id ?? null)) {
        throw new LogicException('Missing open attempt for finished-active corruption.');
    }

    mutateLeaseAttemptBypassingGuard($connection, $attempt->id, [
        'safe_outcome_category' => 'forged_finished',
        'effect_state_after' => 'not_started',
        'finished_at' => $connection->raw('clock_timestamp()'),
    ]);
    $before = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();
    $finishedAttemptBefore = $connection->table('integration_operation_attempts')->where('id', $attempt->id)->first();
    $transitionCount = $connection->table('integration_operation_transitions')->where('operation_id', $receipt->operationId->value)->count();
    $batch = $manager->recoverExpired(leaseScope('fixture_catalog'));
    $after = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();
    $observation = $connection->table('integration_operation_attempts')->where('id', $after?->last_attempt_id)->first();
    $finishedAttemptAfter = $connection->table('integration_operation_attempts')->where('id', $attempt->id)->first();

    if (! $before instanceof stdClass || ! is_int($before->row_version ?? null)) {
        throw new LogicException('Missing pre-quarantine operation version.');
    }

    expect($batch->quarantined)->toBe(1)
        ->and($batch->deferred)->toBe(0)
        ->and($after?->status)->toBe('manual_review')
        ->and($after?->effect_state)->toBe('not_started')
        ->and($after?->row_version)->toBe($before->row_version + 1)
        ->and($after?->active_attempt_id)->toBeNull()
        ->and($after?->lease_token_sha256)->toBeNull()
        ->and($after?->last_attempt_id)->not->toBe($before->last_attempt_id)
        ->and($observation?->safe_outcome_category)->toBe('integrity_quarantined')
        ->and($observation?->error_code)->toBe('integrity_active_attempt_unverifiable')
        ->and($finishedAttemptAfter)->toEqual($finishedAttemptBefore)
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $receipt->operationId->value)->count())->toBe($transitionCount + 1)
        ->and($incidents->incidents)->toHaveCount(1)
        ->and($incidents->incidents[0]->safeCode)->toBe('integrity_active_attempt_unverifiable')
        ->and($incidents->incidents[0]->quarantined)->toBeTrue();
});

it('rolls back every finished-active quarantine write when its lifecycle update fails', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-finished-active-rollback',
    ));
    $manager->claim($receipt->operationId, 'worker:finished-active-rollback')
        ?? throw new LogicException('Missing finished-active rollback lease claim.');
    expireLease($connection, $receipt->operationId);
    $attempt = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->whereNull('finished_at')
        ->first();

    if (! $attempt instanceof stdClass || ! is_string($attempt->id ?? null)) {
        throw new LogicException('Missing open attempt for finished-active rollback corruption.');
    }

    mutateLeaseAttemptBypassingGuard($connection, $attempt->id, [
        'safe_outcome_category' => 'forged_finished',
        'effect_state_after' => 'not_started',
        'finished_at' => $connection->raw('clock_timestamp()'),
    ]);
    $before = leasePersistenceFingerprint($connection, $receipt->operationId);
    installLeaseOperationUpdateFailureTrigger($connection, 'lifecycle');

    try {
        expect(fn () => $manager->recoverExpired(leaseScope('fixture_catalog')))
            ->toThrow(OperationPersistenceFailed::class);
    } finally {
        removeLeaseOperationUpdateFailureTrigger($connection);
    }

    expect(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($before);
});

it('refuses to claim an operation that already has an orphaned unfinished attempt', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-orphan-attempt',
    ));
    $connection->statement('ALTER TABLE integration_operation_attempts DISABLE TRIGGER io_attempts_boundary_coherent');

    try {
        $connection->table('integration_operation_attempts')->insert([
            'id' => app(UlidFactory::class)->generate()->value,
            'operation_id' => $receipt->operationId->value,
            'attempt_no' => 1,
            'mode' => 'dispatch',
            'effect_state_before' => 'not_started',
            'started_at' => $connection->raw('clock_timestamp()'),
            'worker_identity' => 'kernel-dispatch',
        ]);
    } finally {
        $connection->statement('ALTER TABLE integration_operation_attempts ENABLE TRIGGER io_attempts_boundary_coherent');
    }
    $before = leasePersistenceFingerprint($connection, $receipt->operationId);

    expect($manager->claim($receipt->operationId, 'worker:orphan-attempt'))->toBeNull()
        ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($before);
});

it('rolls back an inserted claim attempt when the operation pointer update fails', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-claim-injected-failure',
    ));
    $before = leasePersistenceFingerprint($connection, $receipt->operationId);
    installLeaseOperationUpdateFailureTrigger($connection, 'claim');

    try {
        expect(fn () => $manager->claim($receipt->operationId, 'worker:claim-injected-failure'))
            ->toThrow(OperationPersistenceFailed::class);
    } finally {
        removeLeaseOperationUpdateFailureTrigger($connection);
    }

    expect(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($before)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->doesntExist())->toBeTrue();
});

it('rolls back a deferred observation when its last-attempt pointer update fails', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-deferral-injected-failure',
    ));
    $manager->claim($receipt->operationId, 'worker:deferral-injected-failure')
        ?? throw new LogicException('Missing deferral-injected-failure lease claim.');
    expireLease($connection, $receipt->operationId);
    $operation = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();

    if (! $operation instanceof stdClass || ! is_string($operation->intent_id ?? null)) {
        throw new LogicException('Missing deferral-injected-failure intent.');
    }

    $connection->table('integration_operation_intents')
        ->where('id', $operation->intent_id)
        ->update(['current_generation' => 2]);
    $before = leasePersistenceFingerprint($connection, $receipt->operationId);
    installLeaseOperationUpdateFailureTrigger($connection, 'deferral');

    try {
        expect(fn () => $manager->recoverExpired(leaseScope('fixture_catalog')))
            ->toThrow(OperationPersistenceFailed::class);
    } finally {
        removeLeaseOperationUpdateFailureTrigger($connection);
    }

    expect(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($before)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->count())->toBe(1)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->whereNull('finished_at')->count())->toBe(1);
});

it('rolls back active-attempt finalization and recovery audit when the lifecycle update fails', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-recovery-injected-failure',
    ));
    $manager->claim($receipt->operationId, 'worker:recovery-injected-failure')
        ?? throw new LogicException('Missing recovery-injected-failure lease claim.');
    expireLease($connection, $receipt->operationId);
    $before = leasePersistenceFingerprint($connection, $receipt->operationId);
    installLeaseOperationUpdateFailureTrigger($connection, 'lifecycle');

    try {
        expect(fn () => $manager->recoverExpired(leaseScope('fixture_catalog')))
            ->toThrow(OperationPersistenceFailed::class);
    } finally {
        removeLeaseOperationUpdateFailureTrigger($connection);
    }

    expect(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($before)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->count())->toBe(1)
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->whereNull('finished_at')->count())->toBe(1);
});

it('rolls back a failed quarantine then persists only its durable deferral observation', function (): void {
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-quarantine-injected-failure',
    ));
    $manager->claim($receipt->operationId, 'worker:quarantine-injected-failure')
        ?? throw new LogicException('Missing quarantine-injected-failure lease claim.');
    expireLease($connection, $receipt->operationId);
    insertGhostLeaseResult($connection, $receipt->operationId);
    $before = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();
    $transitionCount = $connection->table('integration_operation_transitions')->where('operation_id', $receipt->operationId->value)->count();
    installLeaseOperationUpdateFailureTrigger($connection, 'lifecycle');

    try {
        $batch = $manager->recoverExpired(leaseScope('fixture_catalog'));
    } finally {
        removeLeaseOperationUpdateFailureTrigger($connection);
    }

    $after = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();
    $attempts = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->orderBy('attempt_no')
        ->get();

    expect($batch->deferred)->toBe(1)
        ->and($batch->quarantined)->toBe(0)
        ->and($after?->status)->toBe('processing')
        ->and($after?->row_version)->toBe($before?->row_version)
        ->and($after?->active_attempt_id)->toBe($before?->active_attempt_id)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->finished_at)->toBeNull()
        ->and($attempts[1]->safe_outcome_category)->toBe('deferred')
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $receipt->operationId->value)->count())->toBe($transitionCount);
});

it('allows exactly one of two PostgreSQL processes to claim an operation', function (): void {
    assertLeaseConcurrencyExtensions();
    [$connection, , $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-concurrent-claim',
    ));
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    if (! $operation instanceof stdClass || ! is_string($operation->intent_id ?? null)) {
        throw new LogicException('Missing concurrent-claim intent identity.');
    }

    /** @var DatabaseManager $database */
    $database = app('db');
    $temporaryPrefix = sys_get_temp_dir().'/integration-operation-lease-'.bin2hex(random_bytes(8));
    $goFile = "{$temporaryPrefix}.go";
    $readyFiles = ["{$temporaryPrefix}.first.ready", "{$temporaryPrefix}.second.ready"];
    $resultFiles = ["{$temporaryPrefix}.first", "{$temporaryPrefix}.second"];
    $applicationNames = [
        'integration-operations-claim-first',
        'integration-operations-claim-second',
    ];
    $remainingChildren = [];
    $database->disconnect('integration_operations_lease_test');
    $locker = null;

    try {
        foreach ($resultFiles as $index => $resultFile) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork the PostgreSQL lease-claim test process.');
            }

            if ($pid === 0) {
                try {
                    $database->purge('integration_operations_lease_test');
                    $childConnection = $database->connection('integration_operations_lease_test');
                    $childConnection->selectOne(
                        "SELECT set_config('application_name', ?, false)",
                        [$applicationNames[$index]],
                    );
                    file_put_contents($readyFiles[$index], 'ready');
                    waitForLeaseStartBarrier($goFile);
                    app()->forgetInstance(OperationLeaseManager::class);
                    /** @var OperationLeaseManager $childManager */
                    $childManager = app(OperationLeaseManager::class);
                    $claim = $childManager->claim(
                        $receipt->operationId,
                        'worker:concurrent-'.($index + 1),
                    );
                    file_put_contents($resultFile, $claim === null ? 'null' : 'claimed');
                    exit(0);
                } catch (Throwable $failure) {
                    file_put_contents($resultFile, 'ERROR:'.$failure::class);
                    exit(1);
                }
            }

            $remainingChildren[$pid] = true;
        }

        foreach ($readyFiles as $readyFile) {
            waitForLeaseStartBarrier($readyFile);
        }

        $configuration = leasePostgresConfiguration();
        config()->set('database.connections.integration_operations_lease_claim_locker', $configuration);
        $database->purge('integration_operations_lease_claim_locker');
        $locker = $database->connection('integration_operations_lease_claim_locker');
        $locker->beginTransaction();
        $locker->table('integration_operation_intents')
            ->where('id', $operation->intent_id)
            ->lockForUpdate()
            ->first();
        file_put_contents($goFile, 'go');
        waitForPostgresLockWaiters($locker, $applicationNames, 10);
        $locker->commit();
        $exitCodes = waitForLeaseChildren($remainingChildren, 15);
        $outcomes = array_map(static fn (string $path): string|false => file_get_contents($path), $resultFiles);
        sort($outcomes);

        expect(array_values($exitCodes))->toBe([0, 0])
            ->and($outcomes)->toBe(['claimed', 'null']);
    } finally {
        if ($locker instanceof Connection && $locker->transactionLevel() > 0) {
            $locker->rollBack();
        }

        $database->purge('integration_operations_lease_claim_locker');
        terminateLeaseChildren($remainingChildren);
        removeLeaseTemporaryFiles([$goFile, ...$readyFiles, ...$resultFiles]);
    }

    $database->purge('integration_operations_lease_test');
    $connection = $database->connection('integration_operations_lease_test');

    expect($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('processing')
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('attempts'))->toBe(1)
        ->and($connection->table('integration_operation_attempts')
            ->where('operation_id', $receipt->operationId->value)
            ->whereNull('finished_at')
            ->count())->toBe(1)
        ->and($connection->table('integration_operation_transitions')
            ->where('operation_id', $receipt->operationId->value)
            ->where('reason_code', 'execution_claimed')
            ->count())->toBe(1)
        ->and($connection->table('integration_operation_transitions')
            ->where('operation_id', $receipt->operationId->value)
            ->count())->toBe(2);
});

it('uses one post-lock database instant for heartbeat expiry and renewal despite application clock skew', function (): void {
    assertLeaseConcurrencyExtensions();
    [$connection, $manager, $coordinator] = prepareLeasePostgresRuntime('fixture_catalog', OwnerMode::ShadowRead);
    config()->set('integration-operations.leases.seconds', 4);
    config()->set('integration-operations.leases.heartbeat_seconds', 1);
    config()->set('integration-operations.leases.connect_timeout_seconds', 1);
    config()->set('integration-operations.leases.request_timeout_seconds', 1);
    config()->set('integration-operations.leases.safety_margin_seconds', 1);
    app()->instance(Clock::class, new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('1900-01-01T00:00:00+00:00');
        }
    });
    $receipt = $coordinator->accept(leaseAcceptCommand(
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        'lease-post-lock-clock',
    ));
    $claim = $manager->claim($receipt->operationId, 'worker:post-lock-clock')
        ?? throw new LogicException('Missing post-lock clock lease claim.');
    $distanceFromDatabaseClock = $connection->selectOne(
        <<<'SQL'
            SELECT abs(extract(epoch FROM (lease_acquired_at - clock_timestamp()))) AS seconds
            FROM integration_operations
            WHERE id = ?
            SQL,
        [$receipt->operationId->value],
    );

    expect($distanceFromDatabaseClock)->toBeInstanceOf(stdClass::class)
        ->and((float) ($distanceFromDatabaseClock->seconds ?? 999))->toBeLessThan(2.0);

    $firstOutcome = runHeartbeatBehindIntentLock($connection, $claim, 1);

    expect($firstOutcome)->toBe('row-version:3')
        ->and($connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->where('lease_expires_at', '>', $connection->raw("clock_timestamp() + INTERVAL '2 seconds'"))
            ->exists())->toBeTrue();

    $renewedClaim = $claim->withRowVersion(3);
    $beforeExpiredWait = leasePersistenceFingerprint($connection, $receipt->operationId);
    $expiredOutcome = runHeartbeatBehindIntentLock($connection, $renewedClaim, 5);

    expect($expiredOutcome)->toBe('null')
        ->and(leasePersistenceFingerprint($connection, $receipt->operationId))->toBe($beforeExpiredWait);
});

function leasePersistenceFingerprint(Connection $connection, OperationId $operationId): string
{
    $serialized = json_encode([
        'operation' => $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->first(),
        'attempts' => $connection->table('integration_operation_attempts')
            ->where('operation_id', $operationId->value)
            ->orderBy('attempt_no')
            ->get()
            ->all(),
        'transitions' => $connection->table('integration_operation_transitions')
            ->where('operation_id', $operationId->value)
            ->orderBy('sequence')
            ->get()
            ->all(),
    ], JSON_THROW_ON_ERROR);

    return hash('sha256', $serialized);
}

function expireLease(Connection $connection, OperationId $operationId): void
{
    $connection->table('integration_operations')
        ->where('id', $operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '1 minute'"),
        ]);
}

function setExpiredLeaseAge(Connection $connection, OperationId $operationId, int $minutes): void
{
    $updated = $connection->update(
        <<<'SQL'
            UPDATE integration_operations
            SET lease_acquired_at = clock_timestamp() - (? * INTERVAL '1 minute'),
                lease_heartbeat_at = clock_timestamp() - (? * INTERVAL '1 minute'),
                lease_expires_at = clock_timestamp() - (? * INTERVAL '1 minute')
            WHERE id = ?
            SQL,
        [$minutes, $minutes - 1, $minutes - 2, $operationId->value],
    );

    expect($updated)->toBe(1);
}

function installLeaseOperationUpdateFailureTrigger(Connection $connection, string $mode): void
{
    if (! in_array($mode, ['claim', 'deferral', 'lifecycle'], true)) {
        throw new InvalidArgumentException('Unknown injected operation-update failure mode.');
    }

    $connection->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION io_test_fail_operation_update() RETURNS trigger AS $$
        DECLARE failure_mode text := current_setting('integration_operations.test_failure_mode', true);
        BEGIN
            IF (failure_mode = 'claim'
                    AND OLD.active_attempt_id IS NULL
                    AND NEW.active_attempt_id IS NOT NULL)
                OR (failure_mode = 'deferral'
                    AND NEW.row_version = OLD.row_version
                    AND NEW.last_attempt_id IS DISTINCT FROM OLD.last_attempt_id)
                OR (failure_mode = 'lifecycle'
                    AND NEW.row_version > OLD.row_version
                    AND NEW.last_attempt_id IS DISTINCT FROM OLD.last_attempt_id) THEN
                RAISE EXCEPTION 'injected operation update failure' USING ERRCODE = '40001';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
        SQL);
    $connection->unprepared(<<<'SQL'
        CREATE TRIGGER io_test_operation_update_failure
        BEFORE UPDATE ON integration_operations
        FOR EACH ROW EXECUTE FUNCTION io_test_fail_operation_update()
        SQL);
    $connection->selectOne(
        "SELECT set_config('integration_operations.test_failure_mode', ?, false)",
        [$mode],
    );
}

function removeLeaseOperationUpdateFailureTrigger(Connection $connection): void
{
    $connection->selectOne(
        "SELECT set_config('integration_operations.test_failure_mode', '', false)",
    );
    $connection->unprepared('DROP TRIGGER IF EXISTS io_test_operation_update_failure ON integration_operations');
    $connection->unprepared('DROP FUNCTION IF EXISTS io_test_fail_operation_update()');
}

function insertGhostLeaseResult(Connection $connection, OperationId $operationId): void
{
    $ciphertext = 'ghost-result-ciphertext';
    $connection->table('integration_operation_results')->insert([
        'operation_id' => $operationId->value,
        'result_type' => 'fixture.operation_result',
        'result_schema_version' => 1,
        'result_key_version' => 1,
        'result_cipher' => 'AES-256-GCM',
        'result_ciphertext' => $ciphertext,
        'result_ciphertext_sha256' => hash('sha256', $ciphertext),
        'created_at' => $connection->raw('clock_timestamp()'),
    ]);
}

/** @param array<string, mixed> $mutation */
function mutateLeaseAttemptBypassingGuard(Connection $connection, string $attemptId, array $mutation): void
{
    $connection->statement('ALTER TABLE integration_operation_attempts DISABLE TRIGGER io_attempts_finalize_once');
    $connection->statement('ALTER TABLE integration_operation_attempts DISABLE TRIGGER io_attempts_boundary_coherent');

    try {
        $updated = $connection->table('integration_operation_attempts')
            ->where('id', $attemptId)
            ->update($mutation);

        expect($updated)->toBe(1);
    } finally {
        $connection->statement('ALTER TABLE integration_operation_attempts ENABLE TRIGGER io_attempts_boundary_coherent');
        $connection->statement('ALTER TABLE integration_operation_attempts ENABLE TRIGGER io_attempts_finalize_once');
    }
}

function assertLeaseConcurrencyExtensions(): void
{
    if (! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')
        || ! function_exists('posix_kill')) {
        Assert::markTestSkipped('The concurrent PostgreSQL lease gate requires the pcntl and posix extensions.');
    }
}

function waitForLeaseStartBarrier(string $startFile): void
{
    $deadline = microtime(true) + 10;

    while (! is_file($startFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrent lease start barrier timed out.');
        }

        usleep(1000);
    }
}

/** @param list<string> $applicationNames */
function waitForPostgresLockWaiters(
    Connection $observer,
    array $applicationNames,
    int $timeoutSeconds,
): void {
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        $waiting = $observer->table('pg_stat_activity')
            ->whereIn('application_name', $applicationNames)
            ->where('wait_event_type', 'Lock')
            ->count();

        if ($waiting === count($applicationNames)) {
            return;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('PostgreSQL workers did not reach the required row-lock wait.');
        }

        usleep(10_000);
    }
}

/**
 * @param  array<int, true>  $remainingChildren
 * @return array<int, int>
 */
function waitForLeaseChildren(array &$remainingChildren, int $timeoutSeconds): array
{
    $exitCodes = [];
    $deadline = microtime(true) + $timeoutSeconds;

    while ($remainingChildren !== []) {
        foreach (array_keys($remainingChildren) as $pid) {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);

            if ($waited === $pid) {
                $exitStatus = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
                $exitCodes[$pid] = is_int($exitStatus) ? $exitStatus : -1;
                unset($remainingChildren[$pid]);
            }
        }

        if ($remainingChildren === []) {
            break;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrent lease workers exceeded the test timeout.');
        }

        usleep(1000);
    }

    return $exitCodes;
}

/** @param array<int, true> $remainingChildren */
function terminateLeaseChildren(array &$remainingChildren): void
{
    foreach (array_keys($remainingChildren) as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        unset($remainingChildren[$pid]);
    }
}

/** @param list<string> $temporaryFiles */
function removeLeaseTemporaryFiles(array $temporaryFiles): void
{
    foreach ($temporaryFiles as $temporaryFile) {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
}

function runHeartbeatBehindIntentLock(Connection $connection, LeaseClaim $claim, int $holdSeconds): string
{
    $operation = $connection->table('integration_operations')
        ->where('id', $claim->operationId->value)
        ->first();

    if (! $operation instanceof stdClass || ! is_string($operation->intent_id ?? null)) {
        throw new LogicException('Missing operation intent for the heartbeat lock test.');
    }

    $temporaryPrefix = sys_get_temp_dir().'/integration-operation-heartbeat-'.bin2hex(random_bytes(8));
    $readyFile = "{$temporaryPrefix}.ready";
    $goFile = "{$temporaryPrefix}.go";
    $resultFile = "{$temporaryPrefix}.result";
    $remainingChildren = [];
    $outcome = null;
    /** @var DatabaseManager $database */
    $database = app('db');
    $database->disconnect('integration_operations_lease_test');
    $locker = null;

    try {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the blocked-heartbeat test process.');
        }

        if ($pid === 0) {
            try {
                $database->purge('integration_operations_lease_test');
                $childConnection = $database->connection('integration_operations_lease_test');
                $childConnection->selectOne(
                    "SELECT set_config('application_name', ?, false)",
                    ['integration-operations-heartbeat-waiter'],
                );
                file_put_contents($readyFile, 'ready');
                waitForLeaseStartBarrier($goFile);
                app()->forgetInstance(OperationLeaseManager::class);
                /** @var OperationLeaseManager $childManager */
                $childManager = app(OperationLeaseManager::class);
                $heartbeated = $childManager->heartbeat($claim);
                file_put_contents(
                    $resultFile,
                    $heartbeated === null ? 'null' : 'row-version:'.$heartbeated->rowVersion,
                );
                exit(0);
            } catch (Throwable $failure) {
                file_put_contents($resultFile, 'ERROR:'.$failure::class);
                exit(1);
            }
        }

        $remainingChildren[$pid] = true;
        waitForLeaseStartBarrier($readyFile);
        $configuration = leasePostgresConfiguration();
        config()->set('database.connections.integration_operations_lease_locker', $configuration);
        $database->purge('integration_operations_lease_locker');
        $locker = $database->connection('integration_operations_lease_locker');
        $locker->beginTransaction();
        $locker->table('integration_operation_intents')
            ->where('id', $operation->intent_id)
            ->lockForUpdate()
            ->first();
        file_put_contents($goFile, 'go');
        waitForPostgresLockWaiters(
            $locker,
            ['integration-operations-heartbeat-waiter'],
            10,
        );
        sleep($holdSeconds);
        $locker->commit();
        $exitCodes = waitForLeaseChildren($remainingChildren, 10);
        $outcome = file_get_contents($resultFile);

        if (array_values($exitCodes) !== [0] || ! is_string($outcome)) {
            throw new RuntimeException('Blocked heartbeat child did not complete successfully.');
        }
    } finally {
        if ($locker instanceof Connection && $locker->transactionLevel() > 0) {
            $locker->rollBack();
        }

        $database->purge('integration_operations_lease_locker');
        terminateLeaseChildren($remainingChildren);
        removeLeaseTemporaryFiles([$readyFile, $goFile, $resultFile]);
    }

    return $outcome;
}

/** @return array{Connection, DatabaseOperationLeaseManager, OperationCoordinator, RecordingLeaseRecoveryIncidentNotifier, DatabaseEffectBoundaryFactory} */
function prepareLeasePostgresRuntime(string $provider, OwnerMode $ownerMode): array
{
    $configuration = leasePostgresConfiguration();
    config()->set('database.connections.integration_operations_lease_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_lease_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.leases.seconds', 30);
    config()->set('integration-operations.leases.heartbeat_seconds', 5);
    config()->set('integration-operations.leases.connect_timeout_seconds', 2);
    config()->set('integration-operations.leases.request_timeout_seconds', 10);
    config()->set('integration-operations.leases.safety_margin_seconds', 5);
    config()->set('integration-operations.runtime.reconciliation_delay_seconds', 2);
    config()->set('integration-operations.writer_fences', [[
        'provider' => $provider,
        'connection' => 'tenant:lease',
        'operation_type' => $provider === 'fixture_catalog'
            ? 'fixture_catalog.record.fetch'
            : 'fixture_dispatch.message.deliver',
        'generation' => 1,
        'owner_mode' => $ownerMode->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_lease_test');
    $connection = $database->connection('integration_operations_lease_test');
    assertLeaseTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_lease_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    $notifier = new RecordingAcceptanceNotifier;
    app()->instance(DurableAcceptanceNotifier::class, $notifier);
    $recoveryIncidents = new RecordingLeaseRecoveryIncidentNotifier;
    app()->instance(LeaseRecoveryIncidentNotifier::class, $recoveryIncidents);
    $definitions = app(DefinitionRegistry::class);

    if ($provider === 'fixture_dispatch') {
        $definitions = new DefinitionRegistry;
        $definitions->register(FakeSingleEffectDefinitionProvider::class);
        $definitions->freeze(app(ContainerBindingInspector::class));
        $coordinator = new DatabaseOperationCoordinator(
            app(KernelDatabase::class),
            $definitions,
            new ConfigLocalReferenceTypeRegistry(['fixture_resource']),
            app(WriterFenceResolver::class),
            app(DatabaseWriterFenceAuthority::class),
            app(LookupHmacKeyRing::class),
            app(HmacSha256::class),
            app(CanonicalJsonV1::class),
            app(BoundPayloadEnvelopeCodec::class),
            app(UlidFactory::class),
            app(OperationStateMachine::class),
            $notifier,
            app(Repository::class),
        );
    } else {
        app()->forgetInstance(OperationCoordinator::class);
        /** @var OperationCoordinator $coordinator */
        $coordinator = app(OperationCoordinator::class);
    }

    $manager = new DatabaseOperationLeaseManager(
        app(KernelDatabase::class),
        $definitions,
        app(ContainerBindingInspector::class),
        app(DatabaseWriterFenceAuthority::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        $recoveryIncidents,
        app(UlidFactory::class),
        app(LeaseTimingPolicy::class),
        app(Repository::class),
    );

    $boundaryFactory = new DatabaseEffectBoundaryFactory(
        app(KernelDatabase::class),
        $definitions,
        app(ContainerBindingInspector::class),
        app(DatabaseWriterFenceAuthority::class),
        app(WriterFenceResolver::class),
        app(HmacSha256::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        app(LeaseTimingPolicy::class),
    );

    return [$connection, $manager, $coordinator, $recoveryIncidents, $boundaryFactory];
}

function leaseAcceptCommand(
    string $provider,
    string $operationType,
    string $slot,
    string $connection = 'tenant:lease',
): AcceptOperation {
    return new AcceptOperation(
        scope: IntegrationScope::of($provider, $connection),
        operationType: new OperationType($operationType),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            $provider === 'fixture_dispatch' ? 'default' : $slot,
            $provider === 'fixture_dispatch'
                ? new LocalReference('fixture_resource', 'resource:'.$slot)
                : null,
        ),
        payload: new CanonicalObject(['value' => $slot]),
        context: IntegrationContext::make("correlation:{$slot}"),
    );
}

function leaseScope(string $provider): IntegrationScope
{
    return IntegrationScope::of($provider, 'tenant:lease');
}

function mutateLeaseIntentLocalType(Connection $connection, string $intentId, string $localType): void
{
    $connection->statement('ALTER TABLE integration_operation_intents DISABLE TRIGGER io_intents_identity_immutable');

    try {
        $updated = $connection->table('integration_operation_intents')
            ->where('id', $intentId)
            ->update(['local_type' => $localType]);

        expect($updated)->toBe(1);
    } finally {
        $connection->statement('ALTER TABLE integration_operation_intents ENABLE TRIGGER io_intents_identity_immutable');
    }
}

/** @return array{concrete: Closure, shared: bool} */
function captureLeaseProviderBinding(): array
{
    $binding = app()->getBindings()[FakeProviderExtensions::class] ?? null;

    if (! is_array($binding)
        || ! ($binding['concrete'] ?? null) instanceof Closure
        || ! is_bool($binding['shared'] ?? null)) {
        throw new LogicException('Missing exact fake provider binding fixture.');
    }

    return [
        'concrete' => $binding['concrete'],
        'shared' => $binding['shared'],
    ];
}

/** @param array{concrete: Closure, shared: bool} $binding */
function restoreLeaseProviderBinding(array $binding): void
{
    app()->bind(FakeProviderExtensions::class, $binding['concrete'], $binding['shared']);
    app()->forgetInstance(FakeProviderExtensions::class);
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function leasePostgresConfiguration(): array
{
    $host = getenv('INTEGRATION_OPERATIONS_TEST_DB_HOST');
    $database = getenv('INTEGRATION_OPERATIONS_TEST_DB_DATABASE');
    $username = getenv('INTEGRATION_OPERATIONS_TEST_DB_USERNAME');
    $password = getenv('INTEGRATION_OPERATIONS_TEST_DB_PASSWORD');
    $allowFresh = getenv('INTEGRATION_OPERATIONS_TEST_DB_ALLOW_FRESH');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)) {
        Assert::markTestSkipped('Set INTEGRATION_OPERATIONS_TEST_DB_* to run the real PostgreSQL gate.');
    }

    PostgresTestDatabaseGuard::assertFreshIsAllowed(
        $database,
        is_string($allowFresh) ? $allowFresh : null,
    );
    $port = getenv('INTEGRATION_OPERATIONS_TEST_DB_PORT');
    $sslMode = getenv('INTEGRATION_OPERATIONS_TEST_DB_SSLMODE');

    return [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => is_string($port) && ctype_digit($port) ? (int) $port : 5432,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
        'sslmode' => is_string($sslMode) && $sslMode !== '' ? $sslMode : 'prefer',
    ];
}

function assertLeaseTestDatabase(Connection $connection, string $configuredDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass || ! is_string($current->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the lease PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase($configuredDatabase, $current->database_name);
}
