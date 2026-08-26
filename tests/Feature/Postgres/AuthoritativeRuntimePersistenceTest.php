<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\CompensationOperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\PendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Exceptions\OperationIntentConflict;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Runtime\DatabaseAuthoritativePollLeaseManager;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeLegacyProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeOperationResult;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativePollingExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeProviderExtensions;
use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingAcceptanceNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptCompensationOperation;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Assert;

it('enforces authoritative contracts, polling, projection, fairness, and compensation persistence', function (): void {
    $configuration = requireAuthoritativePostgresConfiguration();
    config()->set('database.connections.integration_operations_authoritative_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authoritative_test');

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authoritative_test');

    $connection = $database->connection('integration_operations_authoritative_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected authoritative PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    seedAuthoritativeOperation($connection, authoritativePersistenceId('a'));
    seedAuthoritativeOperation($connection, authoritativePersistenceId('b'), generation: 2);
    registerAuthoritativeContract($connection);

    $connection->table('integration_operation_authoritative_states')->insert(
        authoritativeStateRow($connection, authoritativePersistenceId('a'), 'provider_auto_send'),
    );

    expectAuthoritativePostgresFailure($connection, fn (): bool => $connection
        ->table('integration_operation_authoritative_states')
        ->insert(authoritativeStateRow($connection, authoritativePersistenceId('b'), 'undeclared')));

    $connection->table('integration_operation_authoritative_states')->insert(
        authoritativeStateRow($connection, authoritativePersistenceId('b'), 'provider_auto_send'),
    );

    expectAuthoritativePostgresFailure($connection, function () use ($connection): void {
        terminalizeAuthoritativeProviderRejection(
            $connection,
            authoritativePersistenceId('b'),
            authoritativePersistenceId('v'),
            'reconcile',
        );
    });

    $connection->transaction(function () use ($connection): void {
        terminalizeAuthoritativeProviderRejection(
            $connection,
            authoritativePersistenceId('a'),
            authoritativePersistenceId('t'),
            'poll',
        );
    });

    expect($connection->table('integration_operations')
        ->where('id', authoritativePersistenceId('a'))
        ->value('effect_state'))->toBe('not_started')
        ->and($connection->table('integration_operation_authoritative_states')
            ->where('operation_id', authoritativePersistenceId('a'))
            ->value('terminal_proof_kind'))->toBe('poll');

    insertAuthoritativeRuntimeRecords($connection);

    expectAuthoritativePostgresFailure($connection, fn (): int => $connection
        ->table('integration_operation_dispatch_cursors')
        ->where('provider', 'provider')
        ->where('connection_key', 'connection')
        ->where('lane', 'poll')
        ->update(['generation' => 3]));
    expectAuthoritativePostgresFailure($connection, fn (): int => $connection
        ->table('integration_operation_relations')
        ->where('id', authoritativePersistenceId('l'))
        ->update(['slot' => 'other']));
    expectAuthoritativePostgresFailure($connection, fn (): int => $connection
        ->table('integration_operation_projection_states')
        ->where('operation_id', authoritativePersistenceId('a'))
        ->update(['source_row_version' => 1]));

    expect(fn (): int => app(ConsoleKernel::class)->call('migrate:rollback', [
        '--database' => 'integration_operations_authoritative_test',
        '--force' => true,
        '--step' => 1,
    ]))->toThrow(
        RuntimeException::class,
        'Authoritative integration operation runtime data blocks migration rollback.',
    );

    expect($connection->getSchemaBuilder()->hasTable('integration_operation_authoritative_states'))->toBeTrue();

    $freshExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($freshExitCode)->toBe(0);

    $rollbackExitCode = app(ConsoleKernel::class)->call('migrate:rollback', [
        '--database' => 'integration_operations_authoritative_test',
        '--force' => true,
        '--step' => 1,
    ]);

    $operationConstraint = authoritativeConstraintDefinition($connection, 'io_operations_lifecycle_check');
    $attemptConstraint = authoritativeConstraintDefinition($connection, 'io_attempts_bounded_safe_metadata_check');
    $transitionConstraint = authoritativeConstraintDefinition($connection, 'io_transitions_lifecycle_check');
    $operationBoundaryFunction = authoritativeFunctionDefinition($connection, 'io_assert_operation_boundary_coherent()');
    $attemptBoundaryFunction = authoritativeFunctionDefinition($connection, 'io_assert_attempt_boundary_coherent()');

    expect($rollbackExitCode)->toBe(0)
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_authoritative_states'))->toBeFalse()
        ->and($connection->getSchemaBuilder()->hasTable('integration_operations'))->toBeTrue()
        ->and($operationConstraint)->not->toContain('poll_wait')
        ->and($attemptConstraint)->not->toContain("'poll'")
        ->and($transitionConstraint)->not->toContain('poll_wait')
        ->and($operationBoundaryFunction)->not->toContain("'poll'")
        ->and($attemptBoundaryFunction)->not->toContain("'poll'");
});

it('persists the frozen authoritative contract and initial state atomically with acceptance', function (): void {
    $configuration = requireAuthoritativePostgresConfiguration();
    config()->set('database.connections.integration_operations_authoritative_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authoritative_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.leases', [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_authoritative',
        'connection' => 'tenant:authoritative',
        'operation_type' => 'fixture_authoritative.resource.read',
        'generation' => 1,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authoritative_test');
    $connection = $database->connection('integration_operations_authoritative_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected authoritative PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    $migrateFresh = static fn (): int => app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrateFresh())->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $command = authoritativeAcceptanceCommand();
    $now = $connection->raw('CURRENT_TIMESTAMP');

    $connection->table('integration_operation_write_activations')->insert([
        'provider' => 'fixture_authoritative',
        'operation_type' => 'fixture_authoritative.resource.read',
        'handler_version' => 1,
        'activation_slot' => 'default',
        'activation' => 'poll_send_required',
        'created_at' => $now,
    ]);

    expect(fn () => $coordinator->accept($command))->toThrow(OperationIntentConflict::class)
        ->and($connection->table('integration_operation_intents')->count())->toBe(0)
        ->and($connection->table('integration_operations')->count())->toBe(0)
        ->and($connection->table('integration_operation_authoritative_states')->count())->toBe(0);

    expect($migrateFresh())->toBe(0);

    $receipt = $coordinator->accept($command);
    $duplicate = $coordinator->accept($command);
    $state = $connection->table('integration_operation_authoritative_states')
        ->where('operation_id', $receipt->operationId->value)
        ->first();

    /** @var AuthoritativeOperationQuery $query */
    $query = app(AuthoritativeOperationQuery::class);
    $pending = $query
        ->within(IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'))
        ->find($receipt->operationId);

    expect($receipt->wasAlreadyRegistered)->toBeFalse()
        ->and($duplicate->wasAlreadyRegistered)->toBeTrue()
        ->and($duplicate->operationId->equals($receipt->operationId))->toBeTrue()
        ->and($connection->table('integration_operation_intents')->count())->toBe(1)
        ->and($connection->table('integration_operations')->count())->toBe(1)
        ->and($connection->table('integration_operation_authoritative_states')->count())->toBe(1)
        ->and($connection->table('integration_operation_terminal_outcomes')->count())->toBe(4)
        ->and($connection->table('integration_operation_write_activations')->count())->toBe(1)
        ->and($state?->contract_version)->toBe(2)
        ->and($state?->initial_lane)->toBe('execute')
        ->and($state?->write_activation_slot)->toBe('default')
        ->and($state?->result_availability)->toBe('not_ready')
        ->and($pending?->status)->toBe(OperationStatus::Pending)
        ->and($pending?->effectState)->toBe(EffectState::NotStarted)
        ->and($pending?->resultAvailability)->toBe(ResultAvailability::NotReady)
        ->and($pending?->terminalProofKind)->toBeNull()
        ->and($query
            ->within(IntegrationScope::of('fixture_authoritative', 'tenant:foreign'))
            ->find($receipt->operationId))->toBeNull();

    app(OperationProcessor::class)->process($receipt->operationId);
    $terminal = $query
        ->within(IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'))
        ->find($receipt->operationId);

    expect($terminal?->status)->toBe(OperationStatus::Succeeded)
        ->and($terminal?->effectState)->toBe(EffectState::NotStarted)
        ->and($terminal?->resultAvailability)->toBe(ResultAvailability::Available)
        ->and($terminal?->terminalProofKind)->toBe(TerminalProofKind::Execute)
        ->and($terminal?->result)->toEqual(new FakeAuthoritativeOperationResult('executed'))
        ->and(FakeAuthoritativeProviderExtensions::$constructionAttempts)->toBe(4);
});

it('executes a poll-first authoritative operation through a terminal scoped result', function (): void {
    $configuration = requireAuthoritativePostgresConfiguration();
    config()->set('database.connections.integration_operations_authoritative_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authoritative_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.local_references.allowed_types', ['fixture_resource']);
    config()->set('integration-operations.leases', [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_authoritative',
        'connection' => 'tenant:authoritative',
        'operation_type' => 'fixture_authoritative.resource.ensure',
        'generation' => 1,
        'owner_mode' => OwnerMode::On->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authoritative_test');
    $connection = $database->connection('integration_operations_authoritative_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected authoritative PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $receipt = $coordinator->accept(new AcceptOperation(
        scope: IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        operationType: new OperationType('fixture_authoritative.resource.ensure'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            'poll',
            new LocalReference('fixture_resource', 'resource:42'),
        ),
        payload: new CanonicalObject(['resource' => 42]),
        context: IntegrationContext::make('correlation:authoritative-poll'),
    ));
    $accepted = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $acceptedState = $connection->table('integration_operation_authoritative_states')
        ->where('operation_id', $receipt->operationId->value)
        ->first();

    expect($accepted?->status)->toBe(OperationStatus::PollWait->value)
        ->and($accepted?->effect_state)->toBe(EffectState::NotStarted->value)
        ->and($accepted?->attempts)->toBe(0)
        ->and($accepted?->reconcile_attempts)->toBe(0)
        ->and($acceptedState?->initial_lane)->toBe('poll')
        ->and($acceptedState?->poll_attempts)->toBe(0)
        ->and($acceptedState?->poll_purpose)->toBe('preflight')
        ->and($acceptedState?->next_poll_at)->not->toBeNull()
        ->and($acceptedState?->poll_deadline_at)->not->toBeNull();

    $dispatch = app(PendingOperationDispatcher::class)->dispatch(
        IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        25,
    );
    $immediateRedispatch = app(PendingOperationDispatcher::class)->dispatch(
        IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        25,
    );

    expect($dispatch->scanned)->toBe(1)
        ->and($dispatch->dispatched)->toBe(1)
        ->and($dispatch->failures())->toBe(0)
        ->and($immediateRedispatch->scanned)->toBe(0)
        ->and($connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->value('dispatch_attempts'))->toBe(1);

    $pollClaim = app(DatabaseAuthoritativePollLeaseManager::class)
        ->claim($receipt->operationId, 'worker:authoritative-poll')
        ?? throw new LogicException('Missing authoritative poll lease claim.');
    /** @var OperationLeaseManager $leaseManager */
    $leaseManager = app(OperationLeaseManager::class);
    $heartbeat = $leaseManager->heartbeat($pollClaim)
        ?? throw new LogicException('Authoritative poll lease heartbeat failed.');

    expect($heartbeat->purpose)->toBe(LeasePurpose::Poll)
        ->and($heartbeat->rowVersion)->toBe($pollClaim->rowVersion + 1);

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("CURRENT_TIMESTAMP - INTERVAL '1 minute'"),
        ]);

    $recovery = $leaseManager->recoverExpired(
        IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
    );

    expect($recovery->recovered)->toBe(1)
        ->and($connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->value('status'))->toBe(OperationStatus::PollWait->value)
        ->and($leaseManager->heartbeat($heartbeat))->toBeNull();

    app(OperationProcessor::class)->process($receipt->operationId);

    /** @var AuthoritativeOperationQuery $query */
    $query = app(AuthoritativeOperationQuery::class);
    $terminal = $query
        ->within(IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'))
        ->find($receipt->operationId);
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $state = $connection->table('integration_operation_authoritative_states')
        ->where('operation_id', $receipt->operationId->value)
        ->first();
    $attempts = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->orderBy('attempt_no')
        ->get();

    expect($terminal?->status)->toBe(OperationStatus::Succeeded)
        ->and($terminal?->effectState)->toBe(EffectState::NotStarted)
        ->and($terminal?->resultAvailability)->toBe(ResultAvailability::Available)
        ->and($terminal?->terminalProofKind)->toBe(TerminalProofKind::Poll)
        ->and($terminal?->result)->toEqual(new FakeAuthoritativeOperationResult('polled'))
        ->and($query
            ->within(IntegrationScope::of('fixture_authoritative', 'tenant:foreign'))
            ->find($receipt->operationId))->toBeNull()
        ->and($operation?->attempts)->toBe(0)
        ->and($operation?->reconcile_attempts)->toBe(0)
        ->and($operation?->active_attempt_id)->toBeNull()
        ->and($state?->poll_attempts)->toBe(2)
        ->and($state?->result_availability)->toBe(ResultAvailability::Available->value)
        ->and($state?->terminal_proof_kind)->toBe(TerminalProofKind::Poll->value)
        ->and($attempts)->toHaveCount(3)
        ->and($attempts->pluck('attempt_no')->all())->toBe([1, 2, 3])
        ->and($attempts->pluck('mode')->all())->toBe(['poll', 'recovery', 'poll'])
        ->and($attempts->whereNull('finished_at'))->toBeEmpty()
        ->and(FakeAuthoritativePollingExtensions::$constructionAttempts)->toBeGreaterThan(0);
});

it('releases the write lease and resumes durable observation polling after one successful send', function (): void {
    $configuration = requireAuthoritativePostgresConfiguration();
    config()->set('database.connections.integration_operations_authoritative_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authoritative_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.local_references.allowed_types', ['fixture_resource']);
    config()->set('integration-operations.leases', [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_authoritative',
        'connection' => 'tenant:authoritative',
        'operation_type' => 'fixture_authoritative.resource.ensure',
        'generation' => 1,
        'owner_mode' => OwnerMode::On->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authoritative_test');
    $connection = $database->connection('integration_operations_authoritative_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected authoritative PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    expect(app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $receipt = $coordinator->accept(new AcceptOperation(
        scope: IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        operationType: new OperationType('fixture_authoritative.resource.ensure'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            'poll',
            new LocalReference('fixture_resource', 'resource:send-then-poll'),
        ),
        payload: new CanonicalObject(['resource' => 'send-then-poll']),
        context: IntegrationContext::make('correlation:authoritative-send-then-poll'),
    ));

    FakeAuthoritativePollingExtensions::$sendRequiredOnce = true;
    FakeAuthoritativePollingExtensions::$projectObservation = true;
    FakeAuthoritativePollingExtensions::$projectionAttempts = 0;
    FakeAuthoritativePollingExtensions::$projectedStatuses = [];
    FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = true;
    FakeAuthoritativeLegacyProviderExtensions::$awaitPolling = true;

    try {
        app(OperationProcessor::class)->process($receipt->operationId);
        app(OperationProcessor::class)->process($receipt->operationId);

        $afterSend = $connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->first();
        $afterSendState = $connection->table('integration_operation_authoritative_states')
            ->where('operation_id', $receipt->operationId->value)
            ->first();

        expect($afterSend?->status)->toBe(OperationStatus::PollWait->value)
            ->and($afterSend?->effect_state)->toBe(EffectState::Applied->value)
            ->and($afterSend?->active_attempt_id)->toBeNull()
            ->and($afterSend?->lease_owner)->toBeNull()
            ->and($afterSendState?->poll_purpose)->toBe('observation')
            ->and($afterSendState?->next_poll_at)->not->toBeNull()
            ->and($afterSendState?->result_availability)->toBe(ResultAvailability::NotReady->value)
            ->and($connection->table('integration_operation_results')
                ->where('operation_id', $receipt->operationId->value)
                ->count())->toBe(0);

        app(OperationProcessor::class)->process($receipt->operationId);
    } finally {
        FakeAuthoritativePollingExtensions::$sendRequiredOnce = false;
        FakeAuthoritativePollingExtensions::$projectObservation = false;
        FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = false;
        FakeAuthoritativeLegacyProviderExtensions::$awaitPolling = false;
    }

    /** @var AuthoritativeOperationQuery $query */
    $query = app(AuthoritativeOperationQuery::class);
    $terminal = $query
        ->within(IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'))
        ->find($receipt->operationId);

    expect($terminal?->status)->toBe(OperationStatus::Succeeded)
        ->and($terminal?->effectState)->toBe(EffectState::Applied)
        ->and($terminal?->resultAvailability)->toBe(ResultAvailability::Available)
        ->and($terminal?->terminalProofKind)->toBe(TerminalProofKind::Poll)
        ->and($terminal?->result)->toEqual(new FakeAuthoritativeOperationResult('polled'))
        ->and(FakeAuthoritativePollingExtensions::$projectionAttempts)->toBe(2)
        ->and(FakeAuthoritativePollingExtensions::$projectedStatuses)->toBe(['not_sent', 'ok']);
});

it('resumes polling after reconciliation confirms a lost-response write is still processing', function (): void {
    $configuration = requireAuthoritativePostgresConfiguration();
    config()->set('database.connections.integration_operations_authoritative_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authoritative_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.local_references.allowed_types', ['fixture_resource']);
    config()->set('integration-operations.leases', [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]);
    config()->set('integration-operations.runtime.reconciliation_delay_seconds', 1);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_authoritative',
        'connection' => 'tenant:authoritative',
        'operation_type' => 'fixture_authoritative.resource.ensure',
        'generation' => 1,
        'owner_mode' => OwnerMode::On->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authoritative_test');
    $connection = $database->connection('integration_operations_authoritative_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected authoritative PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    expect(app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $receipt = $coordinator->accept(new AcceptOperation(
        scope: IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        operationType: new OperationType('fixture_authoritative.resource.ensure'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            'poll',
            new LocalReference('fixture_resource', 'resource:lost-response-poll'),
        ),
        payload: new CanonicalObject(['resource' => 'lost-response-poll']),
        context: IntegrationContext::make('correlation:authoritative-lost-response-poll'),
    ));

    FakeAuthoritativePollingExtensions::$sendRequiredOnce = true;
    FakeAuthoritativePollingExtensions::$projectObservation = true;
    FakeAuthoritativePollingExtensions::$projectionAttempts = 0;
    FakeAuthoritativePollingExtensions::$projectedStatuses = [];
    FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = true;
    FakeAuthoritativeLegacyProviderExtensions::$throwAfterBoundary = true;
    FakeAuthoritativeProviderExtensions::$classifyAsUncertain = true;

    try {
        app(OperationProcessor::class)->process($receipt->operationId);
        app(OperationProcessor::class)->process($receipt->operationId);

        expect($connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->value('status'))->toBe(OperationStatus::Uncertain->value);

        $connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->update(['next_attempt_at' => $connection->raw('CURRENT_TIMESTAMP')]);
        FakeAuthoritativePollingExtensions::$reconciliationOutcome = AuthoritativeReconciliationOutcome::appliedInProgress(
            'fixture.processing_after_lost_response',
            new CanonicalObject(['status' => 'processing']),
        );

        app(OperationProcessor::class)->process($receipt->operationId);

        $afterReconciliation = $connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->first();
        $afterReconciliationState = $connection->table('integration_operation_authoritative_states')
            ->where('operation_id', $receipt->operationId->value)
            ->first();

        expect($afterReconciliation?->status)->toBe(OperationStatus::PollWait->value)
            ->and($afterReconciliation?->effect_state)->toBe(EffectState::Applied->value)
            ->and($afterReconciliationState?->poll_purpose)->toBe('observation')
            ->and($afterReconciliationState?->result_availability)->toBe(ResultAvailability::NotReady->value)
            ->and($connection->table('integration_operation_results')
                ->where('operation_id', $receipt->operationId->value)
                ->count())->toBe(0)
            ->and(FakeAuthoritativePollingExtensions::$projectedStatuses)->toBe(['not_sent', 'processing']);

        FakeAuthoritativePollingExtensions::$reconciliationOutcome = null;
        app(OperationProcessor::class)->process($receipt->operationId);
    } finally {
        FakeAuthoritativePollingExtensions::$sendRequiredOnce = false;
        FakeAuthoritativePollingExtensions::$projectObservation = false;
        FakeAuthoritativePollingExtensions::$reconciliationOutcome = null;
        FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = false;
        FakeAuthoritativeLegacyProviderExtensions::$throwAfterBoundary = false;
        FakeAuthoritativeProviderExtensions::$classifyAsUncertain = false;
    }

    /** @var AuthoritativeOperationQuery $query */
    $query = app(AuthoritativeOperationQuery::class);
    $terminal = $query
        ->within(IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'))
        ->find($receipt->operationId);

    expect($terminal?->status)->toBe(OperationStatus::Succeeded)
        ->and($terminal?->effectState)->toBe(EffectState::Applied)
        ->and($terminal?->resultAvailability)->toBe(ResultAvailability::Available)
        ->and($terminal?->terminalProofKind)->toBe(TerminalProofKind::Poll)
        ->and($terminal?->result)->toEqual(new FakeAuthoritativeOperationResult('polled'))
        ->and(FakeAuthoritativePollingExtensions::$projectionAttempts)->toBe(3)
        ->and(FakeAuthoritativePollingExtensions::$projectedStatuses)->toBe(['not_sent', 'processing', 'ok']);
});

it('terminalizes an authoritative provider rejection as failed applied with an available result', function (): void {
    $configuration = requireAuthoritativePostgresConfiguration();
    config()->set('database.connections.integration_operations_authoritative_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authoritative_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.local_references.allowed_types', ['fixture_resource']);
    config()->set('integration-operations.leases', [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]);
    config()->set('integration-operations.runtime.reconciliation_delay_seconds', 1);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_authoritative',
        'connection' => 'tenant:authoritative',
        'operation_type' => 'fixture_authoritative.resource.reverse',
        'generation' => 1,
        'owner_mode' => OwnerMode::On->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authoritative_test');
    $connection = $database->connection('integration_operations_authoritative_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected authoritative PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    expect(app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $receipt = $coordinator->accept(new AcceptOperation(
        scope: IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        operationType: new OperationType('fixture_authoritative.resource.reverse'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            'reverse',
            new LocalReference('fixture_resource', 'resource:provider-rejected'),
        ),
        payload: new CanonicalObject(['resource' => 'provider-rejected']),
        context: IntegrationContext::make('correlation:authoritative-provider-rejected'),
    ));

    FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = true;
    FakeAuthoritativeLegacyProviderExtensions::$throwAfterBoundary = true;
    FakeAuthoritativeProviderExtensions::$classifyAsUncertain = true;
    FakeAuthoritativePollingExtensions::$projectObservation = true;
    FakeAuthoritativePollingExtensions::$projectionAttempts = 0;
    FakeAuthoritativePollingExtensions::$projectedStatuses = [];

    try {
        app(OperationProcessor::class)->process($receipt->operationId);

        expect($connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->value('status'))->toBe(OperationStatus::Uncertain->value);

        $connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->update(['next_attempt_at' => $connection->raw('CURRENT_TIMESTAMP')]);
        FakeAuthoritativePollingExtensions::$reconciliationOutcome = AuthoritativeReconciliationOutcome::providerRejected(
            new FakeAuthoritativeOperationResult('provider-rejected'),
            new SafeOperationFailure('fixture_provider_rejected', 'The fixture provider rejected the operation.'),
            'fixture.provider_rejected',
            new CanonicalObject(['status' => 'rejected']),
        );

        app(OperationProcessor::class)->process($receipt->operationId);
    } finally {
        FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = false;
        FakeAuthoritativeLegacyProviderExtensions::$throwAfterBoundary = false;
        FakeAuthoritativeProviderExtensions::$classifyAsUncertain = false;
        FakeAuthoritativePollingExtensions::$projectObservation = false;
        FakeAuthoritativePollingExtensions::$reconciliationOutcome = null;
    }

    /** @var AuthoritativeOperationQuery $query */
    $query = app(AuthoritativeOperationQuery::class);
    $terminal = $query
        ->within(IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'))
        ->find($receipt->operationId);
    $attempts = $connection->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->orderBy('attempt_no')
        ->get();

    expect($terminal?->status)->toBe(OperationStatus::Failed)
        ->and($terminal?->effectState)->toBe(EffectState::Applied)
        ->and($terminal?->resultAvailability)->toBe(ResultAvailability::Available)
        ->and($terminal?->terminalProofKind)->toBe(TerminalProofKind::Reconcile)
        ->and($terminal?->result)->toEqual(new FakeAuthoritativeOperationResult('provider-rejected'))
        ->and($attempts)->toHaveCount(2)
        ->and($attempts->pluck('safe_outcome_category')->all())->toBe(['uncertain', 'provider_rejected'])
        ->and($attempts->first()?->safe_metadata)->toContain('lost_response')
        ->and($attempts->last()?->safe_metadata)->toContain('fixture.provider_rejected')
        ->and(FakeAuthoritativePollingExtensions::$projectionAttempts)->toBe(1)
        ->and(FakeAuthoritativePollingExtensions::$projectedStatuses)->toBe(['rejected']);
});

it('accepts an eligible compensation and its relation atomically and idempotently', function (): void {
    $configuration = requireAuthoritativePostgresConfiguration();
    config()->set('database.connections.integration_operations_authoritative_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authoritative_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.local_references.allowed_types', ['fixture_resource']);
    config()->set('integration-operations.leases', [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]);
    config()->set('integration-operations.writer_fences', [
        [
            'provider' => 'fixture_authoritative',
            'connection' => 'tenant:authoritative',
            'operation_type' => 'fixture_authoritative.resource.ensure',
            'generation' => 1,
            'owner_mode' => OwnerMode::On->value,
            'cohort' => null,
        ],
        [
            'provider' => 'fixture_authoritative',
            'connection' => 'tenant:authoritative',
            'operation_type' => 'fixture_authoritative.resource.reverse',
            'generation' => 1,
            'owner_mode' => OwnerMode::On->value,
            'cohort' => null,
        ],
    ]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authoritative_test');
    $connection = $database->connection('integration_operations_authoritative_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected authoritative PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authoritative_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $parent = $coordinator->accept(new AcceptOperation(
        scope: IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        operationType: new OperationType('fixture_authoritative.resource.ensure'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            'poll',
            new LocalReference('fixture_resource', 'resource:compensated'),
        ),
        payload: new CanonicalObject(['resource' => 'compensated']),
        context: IntegrationContext::make('correlation:authoritative-compensation-parent'),
    ));

    FakeAuthoritativePollingExtensions::$sendRequiredOnce = true;
    FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = true;
    app(OperationProcessor::class)->process($parent->operationId);
    app(OperationProcessor::class)->process($parent->operationId);
    FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = false;

    /** @var AuthoritativeOperationQuery $query */
    $query = app(AuthoritativeOperationQuery::class);
    $parentSnapshot = $query
        ->within(IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'))
        ->find($parent->operationId);

    expect($parentSnapshot?->status)->toBe(OperationStatus::Succeeded)
        ->and($parentSnapshot?->effectState)->toBe(EffectState::Applied)
        ->and($parentSnapshot?->terminalProofKind)->toBe(TerminalProofKind::Execute);

    $compensationCommand = new AcceptCompensationOperation(
        compensatesOperationId: $parent->operationId,
        compensationSlot: 'reverse',
        compensation: new AcceptOperation(
            scope: IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
            operationType: new OperationType('fixture_authoritative.resource.reverse'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            intent: new IntentIdentity(
                'fixture_resource',
                'reverse',
                new LocalReference('fixture_resource', 'resource:compensated'),
            ),
            payload: new CanonicalObject(['resource' => 'compensated']),
            context: IntegrationContext::make('correlation:authoritative-compensation-child'),
        ),
    );
    /** @var CompensationOperationCoordinator $compensations */
    $compensations = app(CompensationOperationCoordinator::class);

    try {
        $connection->transaction(function () use ($compensations, $compensationCommand): void {
            $compensations->acceptCompensation($compensationCommand);

            throw new RuntimeException('rollback compensation acceptance');
        });
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('rollback compensation acceptance');
    }

    expect($connection->table('integration_operations')->count())->toBe(1)
        ->and($connection->table('integration_operation_relations')->count())->toBe(0);

    $child = $compensations->acceptCompensation($compensationCommand);
    $duplicate = $compensations->acceptCompensation($compensationCommand);
    $relation = $connection->table('integration_operation_relations')->sole();

    expect($child->wasAlreadyRegistered)->toBeFalse()
        ->and($duplicate->wasAlreadyRegistered)->toBeTrue()
        ->and($duplicate->operationId->equals($child->operationId))->toBeTrue()
        ->and($relation->parent_operation_id)->toBe($parent->operationId->value)
        ->and($relation->child_operation_id)->toBe($child->operationId->value)
        ->and($relation->purpose)->toBe('compensation')
        ->and($relation->slot)->toBe('reverse')
        ->and($connection->table('integration_operations')->count())->toBe(2)
        ->and($connection->table('integration_operation_relations')->count())->toBe(1);

    $invalid = new AcceptCompensationOperation(
        $parent->operationId,
        'undeclared',
        $compensationCommand->compensation,
    );

    expect(fn () => $compensations->acceptCompensation($invalid))
        ->toThrow(OperationIntentConflict::class)
        ->and($connection->table('integration_operations')->count())->toBe(2)
        ->and($connection->table('integration_operation_relations')->count())->toBe(1);
});

function authoritativeAcceptanceCommand(): AcceptOperation
{
    return new AcceptOperation(
        scope: IntegrationScope::of('fixture_authoritative', 'tenant:authoritative'),
        operationType: new OperationType('fixture_authoritative.resource.read'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity('fixture_resource', 'default'),
        payload: new CanonicalObject(['resource' => 42]),
        context: IntegrationContext::make('correlation:authoritative'),
    );
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function requireAuthoritativePostgresConfiguration(): array
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

function seedAuthoritativeOperation(Connection $connection, string $operationId, int $generation = 1): void
{
    $intentId = $generation === 1
        ? authoritativePersistenceId('i')
        : authoritativePersistenceId('j');
    $now = $connection->raw('CURRENT_TIMESTAMP');

    $connection->table('integration_operation_intents')->insert([
        'id' => $intentId,
        'provider' => 'provider',
        'connection_key' => 'connection',
        'operation_type' => 'provider.invoice.issue',
        'resource_type' => 'invoice',
        'semantic_slot' => 'vat.issue',
        'intent_key_hmac' => authoritativePersistenceDigest($generation === 1 ? 'i' : 'j'),
        'hmac_key_version' => 1,
        'current_generation' => 0,
        'current_operation_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if ($generation === 1) {
        $connection->table('integration_operation_writer_fences')->insert([
            'provider' => 'provider',
            'connection_key' => 'connection',
            'operation_type' => 'provider.invoice.issue',
            'generation' => 1,
            'owner_mode' => 'on',
            'cohort_bound' => false,
            'epoch' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $connection->table('integration_operations')->insert([
        'id' => $operationId,
        'intent_id' => $intentId,
        'intent_generation' => 1,
        'provider' => 'provider',
        'connection_key' => 'connection',
        'operation_type' => 'provider.invoice.issue',
        'resource_type' => 'invoice',
        'semantic_slot' => 'vat.issue',
        'intent_key_hmac' => authoritativePersistenceDigest($generation === 1 ? 'i' : 'j'),
        'current_payload_revision' => 1,
        'payload_schema_version' => 1,
        'handler_version' => 1,
        'result_schema_version' => 1,
        'max_remote_writes' => 1,
        'status' => 'poll_wait',
        'disposition' => 'in_progress',
        'effect_state' => 'not_started',
        'row_version' => 1,
        'writer_generation' => 1,
        'owner_mode_at_accept' => 'on',
        'accepted_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('integration_operation_intents')
        ->where('id', $intentId)
        ->update([
            'current_generation' => 1,
            'current_operation_id' => $operationId,
            'updated_at' => $now,
        ]);
    $connection->table('integration_operation_transitions')->insert([
        'id' => $generation === 1 ? authoritativePersistenceId('s') : authoritativePersistenceId('u'),
        'operation_id' => $operationId,
        'sequence' => 1,
        'from_status' => null,
        'to_status' => 'poll_wait',
        'from_disposition' => null,
        'to_disposition' => 'in_progress',
        'from_effect_state' => null,
        'to_effect_state' => 'not_started',
        'reason_code' => 'accepted_poll_first',
        'actor_category' => 'kernel',
        'expected_row_version' => null,
        'resulting_row_version' => 1,
        'created_at' => $now,
    ]);
}

function registerAuthoritativeContract(Connection $connection): void
{
    $now = $connection->raw('CURRENT_TIMESTAMP');

    $connection->table('integration_operation_write_activations')->insert([
        'provider' => 'provider',
        'operation_type' => 'provider.invoice.issue',
        'handler_version' => 1,
        'activation_slot' => 'provider_auto_send',
        'activation' => 'poll_send_required',
        'created_at' => $now,
    ]);
    $connection->table('integration_operation_terminal_outcomes')->insert([
        'provider' => 'provider',
        'operation_type' => 'provider.invoice.issue',
        'handler_version' => 1,
        'status' => 'failed',
        'effect_state' => 'not_started',
        'result_availability' => 'available',
        'proof_kind' => 'poll',
        'created_at' => $now,
    ]);
}

/** @return array<string, mixed> */
function authoritativeStateRow(Connection $connection, string $operationId, string $slot): array
{
    $now = $connection->raw('CURRENT_TIMESTAMP');

    return [
        'operation_id' => $operationId,
        'contract_version' => 2,
        'initial_lane' => 'poll',
        'write_activation_slot' => $slot,
        'poll_purpose' => 'preflight',
        'poll_attempts' => 0,
        'poll_deadline_at' => $connection->raw("CURRENT_TIMESTAMP + INTERVAL '1 hour'"),
        'next_poll_at' => $now,
        'result_availability' => 'not_ready',
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function insertAuthoritativeRuntimeRecords(Connection $connection): void
{
    $now = $connection->raw('CURRENT_TIMESTAMP');

    $connection->table('integration_operation_dispatch_cursors')->insert([
        'provider' => 'provider',
        'connection_key' => 'connection',
        'lane' => 'poll',
        'last_priority' => 0,
        'last_due_at' => $now,
        'last_operation_id' => authoritativePersistenceId('a'),
        'generation' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $connection->table('integration_operation_projection_states')->insert([
        'operation_id' => authoritativePersistenceId('a'),
        'projection_kind' => 'terminal',
        'target_id' => 'provider.invoice',
        'schema_version' => 1,
        'source_row_version' => 2,
        'applied_row_version' => 2,
        'attempts' => 1,
        'projected_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $connection->table('integration_operation_relations')->insert([
        'id' => authoritativePersistenceId('l'),
        'provider' => 'provider',
        'connection_key' => 'connection',
        'parent_operation_id' => authoritativePersistenceId('a'),
        'child_operation_id' => authoritativePersistenceId('b'),
        'purpose' => 'compensation',
        'slot' => 'reverse',
        'created_at' => $now,
    ]);
}

function terminalizeAuthoritativeProviderRejection(
    Connection $connection,
    string $operationId,
    string $transitionId,
    string $proofKind,
): void {
    $now = $connection->raw('CURRENT_TIMESTAMP');

    $connection->table('integration_operation_results')->insert([
        'operation_id' => $operationId,
        'result_type' => 'provider.invoice.issue_result',
        'result_schema_version' => 1,
        'result_key_version' => 1,
        'result_cipher' => 'AES-256-GCM',
        'result_ciphertext' => 'ciphertext',
        'result_ciphertext_sha256' => authoritativePersistenceDigest($transitionId),
        'created_at' => $now,
    ]);
    $connection->table('integration_operation_authoritative_states')
        ->where('operation_id', $operationId)
        ->update([
            'poll_attempts' => 1,
            'last_polled_at' => $now,
            'result_availability' => 'available',
            'terminal_proof_kind' => $proofKind,
            'updated_at' => $now,
        ]);
    $connection->table('integration_operation_transitions')->insert([
        'id' => $transitionId,
        'operation_id' => $operationId,
        'sequence' => 2,
        'from_status' => 'poll_wait',
        'to_status' => 'failed',
        'from_disposition' => 'in_progress',
        'to_disposition' => 'failed',
        'from_effect_state' => 'not_started',
        'to_effect_state' => 'not_started',
        'reason_code' => 'provider_rejected_before_sdk_write',
        'actor_category' => 'kernel',
        'expected_row_version' => 1,
        'resulting_row_version' => 2,
        'created_at' => $now,
    ]);
    $connection->table('integration_operations')
        ->where('id', $operationId)
        ->update([
            'status' => 'failed',
            'disposition' => 'failed',
            'row_version' => 2,
            'last_safe_failure_code' => 'provider_rejected',
            'last_safe_failure_summary' => 'Provider rejected the operation before the SDK write boundary.',
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
}

/** @param Closure(): mixed $mutation */
function expectAuthoritativePostgresFailure(Connection $connection, Closure $mutation): void
{
    try {
        $connection->transaction(
            fn (): mixed => $mutation(),
            attempts: 1,
        );
        Assert::fail('Expected PostgreSQL to reject an invalid authoritative runtime mutation.');
    } catch (Throwable $failure) {
        expect((string) $failure->getCode())->toBeIn(['23514', '55000']);
    }
}

function authoritativeConstraintDefinition(Connection $connection, string $constraint): string
{
    $row = $connection->selectOne(
        'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
        [$constraint],
    );

    if (! $row instanceof stdClass || ! is_string($row->definition ?? null)) {
        throw new RuntimeException("Unable to read PostgreSQL constraint [{$constraint}].");
    }

    return $row->definition;
}

function authoritativeFunctionDefinition(Connection $connection, string $function): string
{
    $row = $connection->selectOne(
        'SELECT pg_get_functiondef(?::regprocedure) AS definition',
        [$function],
    );

    if (! $row instanceof stdClass || ! is_string($row->definition ?? null)) {
        throw new RuntimeException("Unable to read PostgreSQL function [{$function}].");
    }

    return $row->definition;
}

function authoritativePersistenceId(string $seed): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $suffix = $alphabet[ord($seed) % strlen($alphabet)];

    return '01ARZ3NDEKTSV4RRFFQ69G5FA'.$suffix;
}

function authoritativePersistenceDigest(string $seed): string
{
    return hash('sha256', "authoritative-{$seed}");
}
