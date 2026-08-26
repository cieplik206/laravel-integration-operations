<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Runtime\DatabaseWriterFenceAuthority;
use Cieplik206\IntegrationOperations\Tests\Support\CallbackLookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingExceptionHandler;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Assert;

it('sanitizes cohort preparation and restores exact transaction levels before authority bootstrap', function (): void {
    $configuration = writerFenceAuthorityPostgresConfiguration();
    config()->set('database.connections.integration_operations_authority_test', $configuration);
    config()->set('database.connections.integration_operations_authority_foreign_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authority_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authority_test');
    $database->purge('integration_operations_authority_foreign_test');
    $connection = $database->connection('integration_operations_authority_test');
    $foreign = $database->connection('integration_operations_authority_foreign_test');
    assertWriterFenceAuthorityTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authority_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    $scope = IntegrationScope::of('fixture_dispatch', 'tenant:authority');
    $operationType = new OperationType('fixture_dispatch.message.deliver');
    $fence = new WriterFence(1, OwnerMode::CanaryWrite, 'cohort:authority');
    $originalExceptionHandler = app(ExceptionHandler::class);
    $recordingExceptionHandler = new RecordingExceptionHandler($originalExceptionHandler);
    app()->instance(ExceptionHandler::class, $recordingExceptionHandler);

    try {
        $kernelLeakingAuthority = hostileWriterFenceAuthority(function () use ($connection): void {
            $connection->beginTransaction();
        });

        expect(fn () => $kernelLeakingAuthority->bootstrap($scope, $operationType, $fence))
            ->toThrow(OperationPersistenceFailed::class)
            ->and($connection->transactionLevel())->toBe(0)
            ->and($connection->table('integration_operation_writer_fences')->count())->toBe(0);

        $foreignLeakingAuthority = hostileWriterFenceAuthority(function () use ($foreign): void {
            $foreign->beginTransaction();
        });

        expect(fn () => $foreignLeakingAuthority->bootstrap($scope, $operationType, $fence))
            ->toThrow(OperationPersistenceFailed::class)
            ->and($foreign->transactionLevel())->toBe(0)
            ->and($connection->table('integration_operation_writer_fences')->count())->toBe(0);

        $sentinel = 'writer-fence-authority-hmac-secret-sentinel';
        $throwingAuthority = hostileWriterFenceAuthority(function () use ($connection, $sentinel): void {
            $connection->beginTransaction();

            throw new RuntimeException($sentinel);
        });

        try {
            $throwingAuthority->bootstrap($scope, $operationType, $fence);
            throw new LogicException('Expected writer-fence authority HMAC preparation to fail.');
        } catch (OperationPersistenceFailed $failure) {
            expect((string) $failure)->not->toContain($sentinel)
                ->and($failure->getPrevious())->toBeNull();
        }

        expect($connection->transactionLevel())->toBe(0)
            ->and($recordingExceptionHandler->reported)->toHaveCount(3);

        foreach ($recordingExceptionHandler->reported as $reported) {
            expect($reported)->toBeInstanceOf(OperationPersistenceFailed::class)
                ->and((string) $reported)->not->toContain($sentinel)
                ->and($reported->getPrevious())->toBeNull();
        }

        app(DatabaseWriterFenceAuthority::class)->bootstrap($scope, $operationType, $fence);

        expect($connection->table('integration_operation_writer_fences')->count())->toBe(1)
            ->and($connection->table('integration_operation_writer_fence_aliases')->count())->toBe(1);

        expectWriterFenceAuthoritySqlState($connection, fn (): int => $connection
            ->table('integration_operation_writer_fence_aliases')
            ->where('provider', $scope->provider->value)
            ->where('connection_key', $scope->connection->value)
            ->where('operation_type', $operationType->value)
            ->where('generation', 1)
            ->where('key_version', 1)
            ->update(['retired_at' => $connection->raw('clock_timestamp()')]), '23514');

        expect($connection->table('integration_operation_writer_fence_aliases')
            ->whereNull('retired_at')
            ->count())->toBe(1);
    } finally {
        app()->instance(ExceptionHandler::class, $originalExceptionHandler);

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }

        if ($foreign->transactionLevel() > 0) {
            $foreign->rollBack(0);
        }
    }
});

it('serializes raw old-generation operation inserts against authority cutover at deferred commit', function (string $commitOrdering): void {
    requireWriterFenceAuthorityProcessControl();
    $configuration = writerFenceAuthorityPostgresConfiguration();
    config()->set('database.connections.integration_operations_authority_test', $configuration);
    config()->set('database.connections.integration_operations_authority_observer', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_authority_test');

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_authority_test');
    $connection = $database->connection('integration_operations_authority_test');
    assertWriterFenceAuthorityTestDatabase($connection, $configuration['database']);
    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_authority_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);
    seedWriterFenceCutoverRaceGraph($connection);

    foreach (array_keys($database->getConnections()) as $connectionName) {
        $database->purge((string) $connectionName);
    }

    $directory = sys_get_temp_dir().'/integration-operations-authority-dml-race-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the writer-fence DML race directory.');
    }

    $ready = $directory.'/worker-ready';
    $go = $directory.'/worker-go';
    $result = $directory.'/worker-result';
    $applicationName = 'integration-operations-authority-dml-race-'.$commitOrdering;
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the writer-fence DML race process.');
    }

    if ($pid === 0) {
        if ($commitOrdering === 'operation_first') {
            runWriterFenceCutoverChild($ready, $go, $result, $applicationName);
        }

        runWriterFenceOperationCommitChild($ready, $go, $result, $applicationName);
    }

    try {
        waitForWriterFenceAuthorityFile($ready, 10);
        $connection = $database->connection('integration_operations_authority_test');
        $observer = $database->connection('integration_operations_authority_observer');
        $connection->beginTransaction();

        if ($commitOrdering === 'operation_first') {
            $connection->table('integration_operations')->insert(writerFenceRaceOperationRow(
                $connection,
                writerFenceRaceId('pending'),
                2,
                false,
            ));
            $connection->statement('SET CONSTRAINTS io_operations_writer_fence_authorized_insert IMMEDIATE');
        } else {
            expect(writerFenceRaceCutover($connection))->toBe(1);
        }

        file_put_contents($go, 'go', LOCK_EX);
        waitForWriterFenceAuthorityPostgresLock($observer, $applicationName, 10);
        $parentResult = 'committed';

        try {
            $connection->commit();
        } catch (Throwable $failure) {
            $parentResult = (string) $failure->getCode();

            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }

        waitForWriterFenceAuthorityChild($pid, 10);
        $workerResult = trim((string) file_get_contents($result));
        $database->purge('integration_operations_authority_test');
        $database->purge('integration_operations_authority_observer');
        $observer = $database->connection('integration_operations_authority_test');
        $authorityGeneration = $observer->table('integration_operation_writer_fences')
            ->where('provider', 'fixture_dispatch')
            ->where('connection_key', 'tenant:authority-race')
            ->where('operation_type', 'fixture_dispatch.message.deliver')
            ->value('generation');
        $pendingOldGeneration = $observer->table('integration_operations')
            ->where('id', writerFenceRaceId('pending'))
            ->where('writer_generation', 1)
            ->whereNull('completed_at')
            ->count();

        expect([$parentResult, $workerResult])->toBe($commitOrdering === 'operation_first'
            ? ['committed', '55000']
            : ['committed', '23514'])
            ->and($authorityGeneration)->toBe($commitOrdering === 'operation_first' ? 1 : 2)
            ->and($pendingOldGeneration)->toBe($commitOrdering === 'operation_first' ? 1 : 0)
            ->and($authorityGeneration === 2 && $pendingOldGeneration === 1)->toBeFalse();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }

        if (writerFenceAuthorityChildIsRunning($pid)) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        foreach ([$ready, $go, $result] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
})->with(['operation_first', 'cutover_first']);

function seedWriterFenceCutoverRaceGraph(Connection $connection): void
{
    $now = $connection->raw('clock_timestamp()');

    $connection->table('integration_operation_intents')->insert([
        'id' => writerFenceRaceId('intent'),
        'provider' => 'fixture_dispatch',
        'connection_key' => 'tenant:authority-race',
        'operation_type' => 'fixture_dispatch.message.deliver',
        'resource_type' => 'fixture_resource',
        'semantic_slot' => 'default',
        'intent_key_hmac' => str_repeat('a', 64),
        'hmac_key_version' => 1,
        'current_generation' => 0,
        'current_operation_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $connection->table('integration_operation_writer_fences')->insert([
        'provider' => 'fixture_dispatch',
        'connection_key' => 'tenant:authority-race',
        'operation_type' => 'fixture_dispatch.message.deliver',
        'generation' => 1,
        'owner_mode' => 'on',
        'cohort_bound' => false,
        'epoch' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $connection->table('integration_operations')->insert(writerFenceRaceOperationRow(
        $connection,
        writerFenceRaceId('terminal'),
        1,
        true,
    ));
    $connection->table('integration_operation_intents')
        ->where('id', writerFenceRaceId('intent'))
        ->update([
            'current_generation' => 1,
            'current_operation_id' => writerFenceRaceId('terminal'),
            'updated_at' => $now,
        ]);
}

/** @return array<string, mixed> */
function writerFenceRaceOperationRow(
    Connection $connection,
    string $operationId,
    int $generation,
    bool $terminal,
): array {
    $now = $connection->raw('clock_timestamp()');

    return [
        'id' => $operationId,
        'intent_id' => writerFenceRaceId('intent'),
        'intent_generation' => $generation,
        'supersedes_operation_id' => $generation === 1 ? null : writerFenceRaceId('terminal'),
        'provider' => 'fixture_dispatch',
        'connection_key' => 'tenant:authority-race',
        'operation_type' => 'fixture_dispatch.message.deliver',
        'resource_type' => 'fixture_resource',
        'semantic_slot' => 'default',
        'intent_key_hmac' => str_repeat('a', 64),
        'current_payload_revision' => 1,
        'payload_schema_version' => 1,
        'handler_version' => 1,
        'result_schema_version' => 1,
        'max_remote_writes' => 1,
        'status' => $terminal ? 'cancelled' : 'pending',
        'disposition' => $terminal ? 'cancelled' : 'in_progress',
        'effect_state' => 'not_started',
        'row_version' => 1,
        'writer_generation' => 1,
        'owner_mode_at_accept' => 'on',
        'accepted_at' => $now,
        'completed_at' => $terminal ? $now : null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function runWriterFenceOperationCommitChild(
    string $ready,
    string $go,
    string $result,
    string $applicationName,
): never {
    try {
        /** @var DatabaseManager $database */
        $database = app('db');
        $database->purge('integration_operations_authority_test');
        $connection = $database->connection('integration_operations_authority_test');
        $connection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        $connection->beginTransaction();
        $connection->table('integration_operations')->insert(writerFenceRaceOperationRow(
            $connection,
            writerFenceRaceId('pending'),
            2,
            false,
        ));
        file_put_contents($ready, 'ready', LOCK_EX);
        waitForWriterFenceAuthorityFile($go, 10);

        try {
            $connection->commit();
            file_put_contents($result, 'committed', LOCK_EX);
        } catch (Throwable $failure) {
            file_put_contents($result, (string) $failure->getCode(), LOCK_EX);

            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }

        exit(0);
    } catch (Throwable) {
        file_put_contents($result, 'child_failed', LOCK_EX);
        exit(1);
    }
}

function runWriterFenceCutoverChild(
    string $ready,
    string $go,
    string $result,
    string $applicationName,
): never {
    try {
        /** @var DatabaseManager $database */
        $database = app('db');
        $database->purge('integration_operations_authority_test');
        $connection = $database->connection('integration_operations_authority_test');
        $connection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        file_put_contents($ready, 'ready', LOCK_EX);
        waitForWriterFenceAuthorityFile($go, 10);
        $connection->beginTransaction();

        try {
            if (writerFenceRaceCutover($connection) !== 1) {
                throw new RuntimeException('Writer-fence authority cutover lost its exact CAS.');
            }

            $connection->commit();
            file_put_contents($result, 'committed', LOCK_EX);
        } catch (Throwable $failure) {
            file_put_contents($result, (string) $failure->getCode(), LOCK_EX);

            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }

        exit(0);
    } catch (Throwable) {
        file_put_contents($result, 'child_failed', LOCK_EX);
        exit(1);
    }
}

function writerFenceRaceCutover(Connection $connection): int
{
    return $connection->table('integration_operation_writer_fences')
        ->where('provider', 'fixture_dispatch')
        ->where('connection_key', 'tenant:authority-race')
        ->where('operation_type', 'fixture_dispatch.message.deliver')
        ->where('generation', 1)
        ->where('epoch', 1)
        ->update([
            'generation' => 2,
            'epoch' => 2,
            'updated_at' => $connection->raw('clock_timestamp()'),
        ]);
}

function writerFenceRaceId(string $kind): string
{
    return match ($kind) {
        'intent' => '01ARZ3NDEKTSV4RRFFQ69G5FAA',
        'terminal' => '01ARZ3NDEKTSV4RRFFQ69G5FAB',
        'pending' => '01ARZ3NDEKTSV4RRFFQ69G5FAC',
        default => throw new InvalidArgumentException('Unknown writer-fence race ID fixture.'),
    };
}

function requireWriterFenceAuthorityProcessControl(): void
{
    if (! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')
        || ! function_exists('posix_kill')) {
        Assert::markTestSkipped('The writer-fence authority concurrency gate requires ext-pcntl and ext-posix.');
    }
}

function waitForWriterFenceAuthorityFile(string $path, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the writer-fence authority race barrier.');
        }

        usleep(20_000);
    }
}

function waitForWriterFenceAuthorityPostgresLock(
    Connection $observer,
    string $applicationName,
    int $timeoutSeconds,
): void {
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        $waiting = $observer->table('pg_stat_activity')
            ->where('application_name', $applicationName)
            ->where('wait_event_type', 'Lock')
            ->exists();

        if ($waiting) {
            return;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Writer-fence authority worker never entered a PostgreSQL lock wait.');
        }

        usleep(20_000);
    }
}

function waitForWriterFenceAuthorityChild(int $pid, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        $waited = pcntl_waitpid($pid, $status, WNOHANG);

        if ($waited === $pid) {
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException('Writer-fence authority race worker failed.');
            }

            return;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the writer-fence authority race worker.');
        }

        usleep(20_000);
    }
}

function writerFenceAuthorityChildIsRunning(int $pid): bool
{
    return pcntl_waitpid($pid, $status, WNOHANG) === 0;
}

/** @param Closure(): void $callback */
function hostileWriterFenceAuthority(Closure $callback): DatabaseWriterFenceAuthority
{
    $keyRing = new CallbackLookupHmacKeyRing(
        app(LookupHmacKeyRing::class),
        $callback,
    );

    return new DatabaseWriterFenceAuthority(
        app(KernelDatabase::class),
        new HmacSha256($keyRing, app(CanonicalJsonV1::class)),
        app(UlidFactory::class),
    );
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function writerFenceAuthorityPostgresConfiguration(): array
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

function assertWriterFenceAuthorityTestDatabase(Connection $connection, string $configuredDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass || ! is_string($current->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the writer-fence authority PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase($configuredDatabase, $current->database_name);
}

/** @param Closure(): mixed $mutation */
function expectWriterFenceAuthoritySqlState(Connection $connection, Closure $mutation, string $sqlState): void
{
    try {
        $connection->transaction(fn (): mixed => $mutation(), attempts: 1);
        Assert::fail("Expected PostgreSQL SQLSTATE {$sqlState}.");
    } catch (Throwable $failure) {
        expect((string) $failure->getCode())->toBe($sqlState);
    }
}
