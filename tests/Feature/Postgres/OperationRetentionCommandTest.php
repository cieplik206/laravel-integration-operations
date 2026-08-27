<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Retention\DatabaseOperationRetentionPruner;
use Cieplik206\IntegrationOperations\Retention\OperationRetentionPolicy;
use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingAcceptanceNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Assert;

it('previews retention and only prunes eligible terminal data through the controlled force path', function (): void {
    $configuration = retentionPostgresConfiguration();
    config()->set('database.connections.integration_operations_retention_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_retention_test');
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
    config()->set('integration-operations.runtime.retry_delay_seconds', 60);
    config()->set('integration-operations.runtime.reconciliation_delay_seconds', 120);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_catalog',
        'connection' => 'tenant:retention',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 1,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);
    config()->set('integration-operations.retention', [
        'raw_payload_days' => 30,
        'attempt_diagnostics_days' => 365,
        'terminal_tombstone_days' => 1825,
        'batch_size' => 500,
    ]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_retention_test');
    $connection = $database->connection('integration_operations_retention_test');
    assertRetentionTestDatabase($connection, $configuration['database']);

    /** @var ConsoleKernel $console */
    $console = app(ConsoleKernel::class);
    expect($console->call('migrate:fresh', [
        '--database' => 'integration_operations_retention_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(Command::SUCCESS);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $terminal = $coordinator->accept(retentionCommand('terminal'));
    $pending = $coordinator->accept(retentionCommand('pending'));

    app(OperationProcessor::class)->process($terminal->operationId);

    $terminalAttemptId = $connection
        ->table('integration_operation_attempts')
        ->where('operation_id', $terminal->operationId->value)
        ->value('id');

    expect($terminalAttemptId)->toBeString()
        ->and(fn () => $connection
            ->table('integration_operation_attempts')
            ->where('id', $terminalAttemptId)
            ->update(['safe_metadata' => '{"tampered":true}']))
        ->toThrow(QueryException::class);

    $futureClock = new readonly class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2035-08-27 12:00:00+00:00');
        }
    };
    app()->instance(Clock::class, $futureClock);
    app()->forgetInstance(OperationRetentionPolicy::class);
    app()->forgetInstance(DatabaseOperationRetentionPruner::class);

    expect($console->call('integration-operations:prune'))->toBe(Command::SUCCESS)
        ->and($console->output())->toContain('Preview only', 'raw payload envelopes', 'attempt diagnostics')
        ->and($connection->table('integration_operation_payloads')
            ->where('operation_id', $terminal->operationId->value)
            ->value('payload_pruned_at'))->toBeNull()
        ->and($connection->table('integration_operation_attempts')
            ->where('id', $terminalAttemptId)
            ->value('diagnostics_pruned_at'))->toBeNull();

    expect($console->call('integration-operations:prune', ['--force' => true]))->toBe(Command::SUCCESS)
        ->and($console->output())->toContain('Terminal operation tombstones were preserved');

    $terminalPayload = $connection
        ->table('integration_operation_payloads')
        ->where('operation_id', $terminal->operationId->value)
        ->first();
    $pendingPayload = $connection
        ->table('integration_operation_payloads')
        ->where('operation_id', $pending->operationId->value)
        ->first();
    $terminalAttempt = $connection
        ->table('integration_operation_attempts')
        ->where('id', $terminalAttemptId)
        ->first();

    expect($terminalPayload?->payload_key_version)->toBeNull()
        ->and($terminalPayload?->payload_cipher)->toBeNull()
        ->and($terminalPayload?->payload_ciphertext)->toBeNull()
        ->and($terminalPayload?->payload_ciphertext_sha256)->toBeNull()
        ->and($terminalPayload?->payload_pruned_at)->toBeString()
        ->and($terminalPayload?->payload_fingerprint_hmac)->toBeString()
        ->and($terminalPayload?->context_ciphertext)->toBeString()
        ->and($pendingPayload?->payload_ciphertext)->toBeString()
        ->and($pendingPayload?->payload_pruned_at)->toBeNull()
        ->and($terminalAttempt?->diagnostics_pruned_at)->toBeString()
        ->and($terminalAttempt?->transport)->toBeNull()
        ->and($terminalAttempt?->request_method)->toBeNull()
        ->and($terminalAttempt?->target_template)->toBeNull()
        ->and($terminalAttempt?->safe_metadata)->toBeNull()
        ->and($connection->table('integration_operations')->count())->toBe(2)
        ->and($connection->table('integration_operation_intents')->count())->toBe(2)
        ->and($connection->table('integration_operation_results')->count())->toBe(1)
        ->and($connection->table('integration_operation_transitions')
            ->where('operation_id', $terminal->operationId->value)
            ->count())->toBeGreaterThan(1)
        ->and($connection->table('integration_operation_attempts')->count())->toBe(1);
});

function retentionCommand(string $slot): AcceptOperation
{
    return new AcceptOperation(
        scope: IntegrationScope::of('fixture_catalog', 'tenant:retention'),
        operationType: new OperationType('fixture_catalog.record.fetch'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity('catalog_record', $slot),
        payload: new CanonicalObject(['record' => $slot]),
        context: IntegrationContext::make("correlation:retention:{$slot}"),
    );
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function retentionPostgresConfiguration(): array
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

function assertRetentionTestDatabase(Connection $connection, string $configuredDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass || ! is_string($current->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the retention PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase($configuredDatabase, $current->database_name);
}
