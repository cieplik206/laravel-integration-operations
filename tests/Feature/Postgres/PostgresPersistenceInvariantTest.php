<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Assert;

it('enforces the PostgreSQL lifecycle, immutability, alias, audit, and scheduling invariants', function (): void {
    $configuration = requirePostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');

    $connection = $database->connection('integration_operations_test');
    $currentDatabase = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $currentDatabase instanceof stdClass || ! is_string($currentDatabase->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the connected PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase(
        $configuration['database'],
        $currentDatabase->database_name,
    );

    $migrationPath = dirname(__DIR__, 3).'/database/migrations';

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => $migrationPath,
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    seedValidPersistenceGraph($connection);

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_intents')
        ->insert([
            'id' => '01arz3ndektsv4rrffq69g5fav',
            'provider' => 'provider',
            'connection_key' => 'connection',
            'operation_type' => 'provider.catalog.fetch',
            'resource_type' => 'catalog_record',
            'semantic_slot' => 'invalid_ulid',
            'intent_key_hmac' => persistenceDigest('u'),
            'hmac_key_version' => 1,
            'current_generation' => 0,
            'current_operation_id' => null,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
            'updated_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]), '23514');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operations')
        ->insert([
            ...persistenceOperationRow($connection, persistenceId('m'), 5),
            'operation_type' => 'malformed',
        ]), '23514');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operations')
        ->insert([
            ...persistenceOperationRow($connection, persistenceId('n'), 2),
            'semantic_slot' => 'foreign_slot',
        ]), '23503');

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update(['status' => 'processing']));

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update([
            'status' => 'failed',
            'disposition' => 'failed',
            'completed_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]));

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update(['handler_version' => 2]));

    foreach (['attempts', 'reconcile_attempts', 'dispatch_attempts'] as $counter) {
        expectInvalidPostgresMutation($connection, fn (): int => $connection
            ->table('integration_operations')
            ->where('id', persistenceId('o'))
            ->update([$counter => -1]));
    }

    expectInvalidPostgresMutation($connection, fn (): bool => $connection
        ->table('integration_operations')
        ->insert([
            ...persistenceOperationRow($connection, persistenceId('w'), 2),
            'writer_generation' => 0,
        ]));

    expectInvalidPostgresMutation($connection, fn (): bool => $connection
        ->table('integration_operations')
        ->insert([
            ...persistenceOperationRow($connection, persistenceId('y'), 3),
            'cohort_key_hmac' => persistenceDigest('y'),
            'owner_hmac_key_version' => 0,
        ]));

    expectInvalidPostgresMutation($connection, function () use ($connection): int {
        $connection->statement("SET LOCAL integration_operations.lookup_rotation = 'on'");

        return $connection->table('integration_operation_intents')
            ->where('id', persistenceId('i'))
            ->update(['local_type' => 'different_alias']);
    });

    expectInvalidPostgresMutation($connection, fn (): bool => $connection
        ->table('integration_operation_intents')
        ->insert([
            'id' => persistenceId('k'),
            'provider' => 'provider',
            'connection_key' => 'connection',
            'operation_type' => 'provider.catalog.fetch',
            'resource_type' => 'catalog_record',
            'semantic_slot' => 'invalid_key_version',
            'intent_key_hmac' => persistenceDigest('k'),
            'hmac_key_version' => 0,
            'current_generation' => 0,
            'current_operation_id' => null,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
            'updated_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]));

    expectInvalidPostgresMutation($connection, fn (): bool => $connection
        ->table('integration_operation_lookup_keys')
        ->insert([
            'id' => persistenceId('q'),
            'provider' => 'provider',
            'connection_key' => 'connection',
            'lookup_type' => 'local_reference',
            'subject_id' => persistenceId('i'),
            'intent_id' => persistenceId('i'),
            'operation_id' => null,
            'key_version' => 0,
            'digest' => persistenceDigest('q'),
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]));

    retireIntentAlias($connection);
    insertSecondIntent($connection);

    expectInvalidPostgresMutation($connection, fn (): bool => $connection
        ->table('integration_operation_lookup_keys')
        ->insert([
            'id' => persistenceId('v'),
            'provider' => 'other_provider',
            'connection_key' => 'other-connection',
            'lookup_type' => 'intent',
            'subject_id' => persistenceId('j'),
            'intent_id' => persistenceId('j'),
            'operation_id' => null,
            'key_version' => 1,
            'digest' => persistenceDigest('a'),
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]));

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operation_lookup_keys')
        ->where('id', persistenceId('l'))
        ->update(['digest' => persistenceDigest('b')]));

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operation_lookup_keys')
        ->where('id', persistenceId('l'))
        ->delete());

    expectInvalidPostgresMutation($connection, fn (): bool => $connection
        ->table('integration_operation_transitions')
        ->insert([
            'id' => persistenceId('x'),
            'operation_id' => persistenceId('o'),
            'sequence' => 2,
            'from_status' => null,
            'to_status' => 'processing',
            'from_disposition' => null,
            'to_disposition' => 'in_progress',
            'from_effect_state' => null,
            'to_effect_state' => 'not_started',
            'reason_code' => 'invalid_sequence',
            'actor_category' => 'kernel',
            'expected_row_version' => null,
            'resulting_row_version' => 2,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]));

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operation_transitions')
        ->where('id', persistenceId('t'))
        ->update(['reason_code' => 'rewritten']));

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operation_attempts')
        ->where('id', persistenceId('a'))
        ->update(['safe_outcome_category' => 'rewritten']));

    verifyOpenAttemptCapabilityInvariants($connection);

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operation_payloads')
        ->where('id', persistenceId('p'))
        ->update([
            'context_ciphertext' => 'rewrapped-context',
            'context_ciphertext_sha256' => persistenceDigest('d'),
        ]));

    $connection->transaction(function () use ($connection): void {
        $connection->statement("SET LOCAL integration_operations.reencryption = 'on'");
        $updated = $connection->table('integration_operation_payloads')
            ->where('id', persistenceId('p'))
            ->update([
                'context_ciphertext' => 'rewrapped-context',
                'context_ciphertext_sha256' => persistenceDigest('d'),
            ]);

        expect($updated)->toBe(1);
    });

    prunePayload($connection);

    expectInvalidPostgresMutation($connection, fn (): int => $connection
        ->table('integration_operation_payloads')
        ->where('id', persistenceId('p'))
        ->update([
            'payload_key_version' => 1,
            'payload_cipher' => 'AES-256-GCM',
            'payload_ciphertext' => 'restored',
            'payload_ciphertext_sha256' => persistenceDigest('e'),
            'payload_pruned_at' => null,
        ]));

    $indexes = $connection->table('pg_indexes')
        ->where('schemaname', 'public')
        ->whereIn('indexname', [
            'io_operations_dispatch_due_idx',
            'io_operations_reconcile_due_idx',
            'io_operations_expired_lease_idx',
            'io_operations_terminal_tombstone_idx',
        ])
        ->pluck('indexdef', 'indexname')
        ->all();

    expect($indexes)->toHaveCount(4);

    foreach ($indexes as $definition) {
        expect($definition)->toBeString()
            ->toContain('provider', 'connection_key')
            ->toContain(' WHERE ');
    }

    expect($indexes['io_operations_dispatch_due_idx'])->toContain('pending', 'retry_wait')
        ->and($indexes['io_operations_reconcile_due_idx'])->toContain('uncertain')
        ->and($indexes['io_operations_expired_lease_idx'])->toContain('processing', 'reconciling')
        ->and($indexes['io_operations_terminal_tombstone_idx'])->toContain('succeeded', 'failed', 'cancelled');

    $openAttemptIndex = $connection->table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('indexname', 'io_attempts_one_open_per_operation_unique')
        ->value('indexdef');

    expect($openAttemptIndex)->toBeString()
        ->toContain('UNIQUE', 'operation_id', 'WHERE (finished_at IS NULL)');

    $backoffIndex = $connection->table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('indexname', 'io_attempts_recovery_backoff_idx')
        ->value('indexdef');
    $attemptPointerConstraints = $connection->table('pg_constraint')
        ->whereIn('conname', [
            'io_operations_active_attempt_fk',
            'io_operations_last_attempt_fk',
        ])
        ->get(['conname', 'condeferrable']);

    expect($backoffIndex)->toBeString()
        ->toContain('operation_id', 'retry_after_at', 'recovery', 'safe_outcome_category', 'deferred')
        ->and($attemptPointerConstraints)->toHaveCount(2);

    foreach ($attemptPointerConstraints as $constraint) {
        expect($constraint->condeferrable ?? null)->toBeFalse();
    }

    $retentionRollbackExitCode = app(ConsoleKernel::class)->call('migrate:rollback', [
        '--database' => 'integration_operations_test',
        '--force' => true,
        '--step' => 1,
    ]);

    expect($retentionRollbackExitCode)->toBe(0)
        ->and($connection->getSchemaBuilder()->hasColumn(
            'integration_operation_attempts',
            'diagnostics_pruned_at',
        ))->toBeFalse()
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_authoritative_states'))->toBeTrue();

    $authoritativeRuntimeRollbackExitCode = app(ConsoleKernel::class)->call('migrate:rollback', [
        '--database' => 'integration_operations_test',
        '--force' => true,
        '--step' => 1,
    ]);

    expect($authoritativeRuntimeRollbackExitCode)->toBe(0)
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_authoritative_states'))->toBeFalse()
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_writer_fences'))->toBeTrue()
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_attempts'))->toBeTrue();

    $authorityRollbackExitCode = app(ConsoleKernel::class)->call('migrate:rollback', [
        '--database' => 'integration_operations_test',
        '--force' => true,
        '--step' => 1,
    ]);

    expect($authorityRollbackExitCode)->toBe(0)
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_writer_fences'))->toBeFalse()
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_attempts'))->toBeTrue();

    $auditRollbackExitCode = app(ConsoleKernel::class)->call('migrate:rollback', [
        '--database' => 'integration_operations_test',
        '--force' => true,
        '--step' => 1,
    ]);

    expect($auditRollbackExitCode)->toBe(0)
        ->and($connection->getSchemaBuilder()->hasTable('integration_operation_attempts'))->toBeFalse()
        ->and($connection->getSchemaBuilder()->hasTable('integration_operations'))->toBeTrue();
});

/** @param Closure(): mixed $mutation */
function expectInvalidPostgresMutation(Connection $connection, Closure $mutation): void
{
    expect(fn (): mixed => $connection->transaction(
        fn (): mixed => $mutation(),
        attempts: 1,
    ))
        ->toThrow(QueryException::class);
}

/** @param Closure(): mixed $mutation */
function expectPostgresSqlState(Connection $connection, Closure $mutation, string $sqlState): void
{
    try {
        $connection->transaction(fn (): mixed => $mutation(), attempts: 1);
        Assert::fail("Expected PostgreSQL SQLSTATE {$sqlState}.");
    } catch (Throwable $failure) {
        expect((string) $failure->getCode())->toBe($sqlState);
    }
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function requirePostgresConfiguration(): array
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

function seedValidPersistenceGraph(Connection $connection): void
{
    $now = $connection->raw('CURRENT_TIMESTAMP');

    $connection->table('integration_operation_intents')->insert([
        'id' => persistenceId('i'),
        'provider' => 'provider',
        'connection_key' => 'connection',
        'operation_type' => 'provider.catalog.fetch',
        'resource_type' => 'catalog_record',
        'semantic_slot' => 'default',
        'local_type' => 'catalog_record',
        'local_id_key_version' => 1,
        'local_id_cipher' => 'AES-256-GCM',
        'local_id_ciphertext' => 'local-reference-ciphertext',
        'local_id_ciphertext_sha256' => persistenceDigest('r'),
        'local_reference_hmac' => persistenceDigest('h'),
        'intent_key_hmac' => persistenceDigest('i'),
        'hmac_key_version' => 1,
        'current_generation' => 0,
        'current_operation_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('integration_operation_lookup_keys')->insert([
        'id' => persistenceId('l'),
        'provider' => 'provider',
        'connection_key' => 'connection',
        'lookup_type' => 'intent',
        'subject_id' => persistenceId('i'),
        'intent_id' => persistenceId('i'),
        'operation_id' => null,
        'key_version' => 1,
        'digest' => persistenceDigest('a'),
        'created_at' => $now,
    ]);

    $connection->table('integration_operation_writer_fences')->insert([
        'provider' => 'provider',
        'connection_key' => 'connection',
        'operation_type' => 'provider.catalog.fetch',
        'generation' => 1,
        'owner_mode' => 'on',
        'cohort_bound' => false,
        'epoch' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('integration_operations')->insert(
        persistenceOperationRow($connection, persistenceId('o'), 1),
    );

    $connection->table('integration_operation_intents')
        ->where('id', persistenceId('i'))
        ->update([
            'current_generation' => 1,
            'current_operation_id' => persistenceId('o'),
            'updated_at' => $now,
        ]);

    $connection->table('integration_operation_payloads')->insert([
        'id' => persistenceId('p'),
        'operation_id' => persistenceId('o'),
        'payload_revision' => 1,
        'payload_key_version' => 1,
        'payload_cipher' => 'AES-256-GCM',
        'payload_ciphertext' => 'payload-ciphertext',
        'payload_ciphertext_sha256' => persistenceDigest('p'),
        'payload_fingerprint_hmac' => persistenceDigest('f'),
        'hmac_key_version' => 1,
        'payload_schema_version' => 1,
        'context_key_version' => 1,
        'context_cipher' => 'AES-256-GCM',
        'context_ciphertext' => 'context-ciphertext',
        'context_ciphertext_sha256' => persistenceDigest('c'),
        'context_schema_version' => 1,
        'created_by_actor' => 'test',
        'created_at' => $now,
    ]);

    $connection->table('integration_operation_transitions')->insert([
        'id' => persistenceId('t'),
        'operation_id' => persistenceId('o'),
        'sequence' => 1,
        'from_status' => null,
        'to_status' => 'pending',
        'from_disposition' => null,
        'to_disposition' => 'in_progress',
        'from_effect_state' => null,
        'to_effect_state' => 'not_started',
        'reason_code' => 'accepted',
        'actor_category' => 'kernel',
        'expected_row_version' => null,
        'resulting_row_version' => 1,
        'created_at' => $now,
    ]);

    $connection->table('integration_operation_attempts')->insert([
        'id' => persistenceId('a'),
        'operation_id' => persistenceId('o'),
        'attempt_no' => 1,
        'mode' => 'dispatch',
        'safe_outcome_category' => 'dispatch_recorded',
        'effect_state_before' => 'not_started',
        'effect_state_after' => 'not_started',
        'started_at' => $now,
        'finished_at' => $now,
        'worker_identity' => 'test-worker',
    ]);
}

/** @return array<string, mixed> */
function persistenceOperationRow(Connection $connection, string $operationId, int $generation): array
{
    $now = $connection->raw('CURRENT_TIMESTAMP');

    return [
        'id' => $operationId,
        'intent_id' => persistenceId('i'),
        'intent_generation' => $generation,
        'provider' => 'provider',
        'connection_key' => 'connection',
        'operation_type' => 'provider.catalog.fetch',
        'resource_type' => 'catalog_record',
        'semantic_slot' => 'default',
        'intent_key_hmac' => persistenceDigest('i'),
        'current_payload_revision' => 1,
        'payload_schema_version' => 1,
        'handler_version' => 1,
        'result_schema_version' => 1,
        'max_remote_writes' => 1,
        'status' => 'pending',
        'disposition' => 'in_progress',
        'effect_state' => 'not_started',
        'row_version' => 1,
        'writer_generation' => 1,
        'owner_mode_at_accept' => 'on',
        'accepted_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function retireIntentAlias(Connection $connection): void
{
    $updated = $connection->table('integration_operation_lookup_keys')
        ->where('id', persistenceId('l'))
        ->update(['retired_at' => $connection->raw('CURRENT_TIMESTAMP')]);

    expect($updated)->toBe(1);
}

function insertSecondIntent(Connection $connection): void
{
    $now = $connection->raw('CURRENT_TIMESTAMP');

    $connection->table('integration_operation_intents')->insert([
        'id' => persistenceId('j'),
        'provider' => 'other_provider',
        'connection_key' => 'other-connection',
        'operation_type' => 'other_provider.catalog.fetch',
        'resource_type' => 'catalog_record',
        'semantic_slot' => 'default',
        'intent_key_hmac' => persistenceDigest('j'),
        'hmac_key_version' => 1,
        'current_generation' => 0,
        'current_operation_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function verifyOpenAttemptCapabilityInvariants(Connection $connection): void
{
    $capturedNow = $connection->selectOne('SELECT clock_timestamp() AS observed_at');

    if (! $capturedNow instanceof stdClass || ! is_string($capturedNow->observed_at ?? null)) {
        throw new RuntimeException('Unable to capture a stable PostgreSQL attempt instant.');
    }

    $now = $capturedNow->observed_at;
    $tokenSha256 = hash('sha256', 'lease-capability');

    expectPostgresSqlState($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update([
            'status' => 'processing',
            'lease_owner' => 'worker:uppercase',
            'lease_token_sha256' => str_repeat('A', 64),
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '2 seconds'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '1 second'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() + INTERVAL '1 minute'"),
        ]), '23514');

    expectPostgresSqlState($connection, function () use ($connection, $now, $tokenSha256): void {
        $connection->transaction(function () use ($connection, $now, $tokenSha256): void {
            $connection->table('integration_operation_attempts')->insert([
                'id' => persistenceId('b'),
                'operation_id' => persistenceId('o'),
                'attempt_no' => 2,
                'mode' => 'execute',
                'effect_state_before' => 'not_started',
                'started_at' => $now,
                'worker_identity' => 'worker:one',
                'lease_token_sha256' => $tokenSha256,
            ]);
            $connection->table('integration_operations')
                ->where('id', persistenceId('o'))
                ->update([
                    'status' => 'processing',
                    'lease_owner' => 'worker:one',
                    'lease_token_sha256' => $tokenSha256,
                    'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '2 seconds'"),
                    'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '1 second'"),
                    'lease_expires_at' => $connection->raw("clock_timestamp() + INTERVAL '1 minute'"),
                    'active_attempt_id' => persistenceId('b'),
                    'last_attempt_id' => null,
                ]);
        });
    }, '23514');

    $claimed = $connection->transaction(function () use ($connection, $now, $tokenSha256): int {
        $connection->table('integration_operation_attempts')->insert([
            'id' => persistenceId('b'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 2,
            'mode' => 'execute',
            'effect_state_before' => 'not_started',
            'started_at' => $now,
            'worker_identity' => 'worker:one',
            'lease_token_sha256' => $tokenSha256,
        ]);

        return $connection->table('integration_operations')
            ->where('id', persistenceId('o'))
            ->update([
                'status' => 'processing',
                'lease_owner' => 'worker:one',
                'lease_token_sha256' => $tokenSha256,
                'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '2 seconds'"),
                'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '1 second'"),
                'lease_expires_at' => $connection->raw("clock_timestamp() + INTERVAL '1 minute'"),
                'active_attempt_id' => persistenceId('b'),
                'last_attempt_id' => persistenceId('b'),
            ]);
    });

    expect($claimed)->toBe(1);

    $connection->table('integration_operations')->insert(
        persistenceOperationRow($connection, persistenceId('n'), 4),
    );
    $connection->table('integration_operation_attempts')->insert([
        'id' => persistenceId('z'),
        'operation_id' => persistenceId('n'),
        'attempt_no' => 1,
        'mode' => 'recovery',
        'safe_outcome_category' => 'lease_recovered',
        'effect_state_before' => 'not_started',
        'effect_state_after' => 'not_started',
        'started_at' => $now,
        'finished_at' => $now,
        'worker_identity' => 'kernel-recovery',
    ]);

    expectPostgresSqlState($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update(['active_attempt_id' => persistenceId('z')]), '23503');
    expectPostgresSqlState($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update(['last_attempt_id' => persistenceId('z')]), '23503');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('c'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 3,
            'mode' => 'execute',
            'effect_state_before' => 'not_started',
            'started_at' => $now,
            'worker_identity' => 'worker:two',
            'lease_token_sha256' => hash('sha256', 'other-capability'),
        ]), '23505');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('d'),
            'operation_id' => persistenceId('n'),
            'attempt_no' => 2,
            'mode' => 'dispatch',
            'effect_state_before' => 'not_started',
            'effect_state_after' => 'applied',
            'started_at' => $now,
            'worker_identity' => 'worker:premature-effect',
        ]), '23514');

    expectPostgresSqlState($connection, fn (): int => $connection
        ->table('integration_operation_attempts')
        ->where('id', persistenceId('b'))
        ->update([
            'safe_outcome_category' => 'request_not_started',
            'effect_state_after' => 'not_started',
            'finished_at' => $now,
        ]), '23514');

    expectPostgresSqlState($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update([
            'status' => 'manual_review',
            'disposition' => 'requires_manual_review',
            'lease_owner' => null,
            'lease_token_sha256' => null,
            'lease_acquired_at' => null,
            'lease_heartbeat_at' => null,
            'lease_expires_at' => null,
            'active_attempt_id' => null,
        ]), '23514');

    $finalized = $connection->transaction(function () use ($connection, $now): int {
        $connection->table('integration_operation_attempts')
            ->where('id', persistenceId('b'))
            ->update([
                'safe_outcome_category' => 'request_not_started',
                'effect_state_after' => 'not_started',
                'finished_at' => $now,
            ]);

        return $connection->table('integration_operations')
            ->where('id', persistenceId('o'))
            ->update([
                'status' => 'manual_review',
                'disposition' => 'requires_manual_review',
                'lease_owner' => null,
                'lease_token_sha256' => null,
                'lease_acquired_at' => null,
                'lease_heartbeat_at' => null,
                'lease_expires_at' => null,
                'active_attempt_id' => null,
            ]);
    });

    expect($finalized)->toBe(1);

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('d'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 3,
            'mode' => 'execute',
            'effect_state_before' => 'not_started',
            'started_at' => $now,
            'worker_identity' => 'worker:missing-token',
        ]), '23514');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('g'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 3,
            'mode' => 'execute',
            'effect_state_before' => 'not_started',
            'started_at' => $now,
            'worker_identity' => 'worker:uppercase-token',
            'lease_token_sha256' => str_repeat('A', 64),
        ]), '23514');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('e'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 3,
            'mode' => 'recovery',
            'safe_outcome_category' => 'recovered',
            'effect_state_before' => 'not_started',
            'effect_state_after' => 'not_started',
            'started_at' => $now,
            'finished_at' => $now,
            'worker_identity' => 'kernel-recovery',
            'lease_token_sha256' => $tokenSha256,
        ]), '23514');

    $reclaimed = $connection->transaction(function () use ($connection, $now): int {
        $connection->table('integration_operation_attempts')->insert([
            'id' => persistenceId('f'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 3,
            'mode' => 'execute',
            'effect_state_before' => 'not_started',
            'started_at' => $now,
            'worker_identity' => 'worker:three',
            'lease_token_sha256' => hash('sha256', 'third-capability'),
        ]);

        return $connection->table('integration_operations')
            ->where('id', persistenceId('o'))
            ->update([
                'status' => 'processing',
                'disposition' => 'in_progress',
                'lease_owner' => 'worker:three',
                'lease_token_sha256' => hash('sha256', 'third-capability'),
                'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '2 seconds'"),
                'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '1 second'"),
                'lease_expires_at' => $connection->raw("clock_timestamp() + INTERVAL '1 minute'"),
                'active_attempt_id' => persistenceId('f'),
                'last_attempt_id' => persistenceId('f'),
            ]);
    });

    expect($reclaimed)->toBe(1);

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('e'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 4,
            'mode' => 'recovery',
            'safe_outcome_category' => 'deferred',
            'effect_state_before' => 'not_started',
            'effect_state_after' => 'not_started',
            'started_at' => $now,
            'finished_at' => $now,
            'worker_identity' => 'kernel-recovery',
        ]), '23514');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('g'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 4,
            'mode' => 'recovery',
            'safe_outcome_category' => 'deferred',
            'effect_state_before' => 'not_started',
            'effect_state_after' => 'not_started',
            'started_at' => $now,
            'finished_at' => $now,
            'retry_after_at' => $now,
            'worker_identity' => 'kernel-recovery',
        ]), '23514');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('h'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 4,
            'mode' => 'execute',
            'safe_outcome_category' => 'deferred',
            'effect_state_before' => 'not_started',
            'effect_state_after' => 'not_started',
            'started_at' => $now,
            'finished_at' => $now,
            'retry_after_at' => $connection->raw("clock_timestamp() + INTERVAL '1 minute'"),
            'worker_identity' => 'worker:invalid-deferred',
            'lease_token_sha256' => hash('sha256', 'invalid-deferred'),
        ]), '23514');

    expectPostgresSqlState($connection, fn (): bool => $connection
        ->table('integration_operation_attempts')
        ->insert([
            'id' => persistenceId('s'),
            'operation_id' => persistenceId('o'),
            'attempt_no' => 4,
            'mode' => 'execute',
            'safe_outcome_category' => 'request_not_started',
            'effect_state_before' => 'not_started',
            'effect_state_after' => 'not_started',
            'started_at' => $now,
            'finished_at' => $now,
            'retry_after_at' => $connection->raw("clock_timestamp() + INTERVAL '1 minute'"),
            'worker_identity' => 'worker:invalid-retry-after',
            'lease_token_sha256' => hash('sha256', 'invalid-retry-after'),
        ]), '23514');

    $connection->table('integration_operation_attempts')->insert([
        'id' => persistenceId('r'),
        'operation_id' => persistenceId('o'),
        'attempt_no' => 4,
        'mode' => 'recovery',
        'safe_outcome_category' => 'deferred',
        'effect_state_before' => 'not_started',
        'effect_state_after' => 'not_started',
        'started_at' => $now,
        'finished_at' => $now,
        'retry_after_at' => $connection->raw("clock_timestamp() + INTERVAL '1 minute'"),
        'worker_identity' => 'kernel-recovery',
    ]);
    $lastUpdated = $connection->table('integration_operations')
        ->where('id', persistenceId('o'))
        ->update(['last_attempt_id' => persistenceId('r')]);

    expect($lastUpdated)->toBe(1)
        ->and($connection->table('integration_operation_attempts')
            ->where('operation_id', persistenceId('o'))
            ->whereNull('finished_at')
            ->count())->toBe(1);
}

function prunePayload(Connection $connection): void
{
    $updated = $connection->table('integration_operation_payloads')
        ->where('id', persistenceId('p'))
        ->update([
            'payload_key_version' => null,
            'payload_cipher' => null,
            'payload_ciphertext' => null,
            'payload_ciphertext_sha256' => null,
            'payload_pruned_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);

    expect($updated)->toBe(1);
}

function persistenceId(string $character): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $suffix = $alphabet[ord($character) % strlen($alphabet)];

    return '01ARZ3NDEKTSV4RRFFQ69G5FA'.$suffix;
}

function persistenceDigest(string $character): string
{
    return str_repeat($character, 64);
}
