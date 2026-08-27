<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Events\OperationTerminalized;
use Cieplik206\IntegrationOperations\Exceptions\CrossConnectionTransaction;
use Cieplik206\IntegrationOperations\Exceptions\DurableAcceptanceNotificationFailed;
use Cieplik206\IntegrationOperations\Exceptions\LocalReferenceRequired;
use Cieplik206\IntegrationOperations\Exceptions\ManagedMutationIdentityRejected;
use Cieplik206\IntegrationOperations\Exceptions\OperationIntentConflict;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceRejected;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\ConfigLocalReferenceTypeRegistry;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationCoordinator;
use Cieplik206\IntegrationOperations\Runtime\DatabasePendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Runtime\DatabaseWriterFenceAuthority;
use Cieplik206\IntegrationOperations\Runtime\OperationStateMachine;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeOperationResult;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\Tests\Support\CallbackDurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Tests\Support\CallbackWriterFenceResolver;
use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingExceptionHandler;
use Cieplik206\IntegrationOperations\Tests\Support\RollbackFailingConnection;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationActor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\Sha256Digest;
use Cieplik206\IntegrationOperations\ValueObjects\SupersedeFailedOperation;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

it('accepts idempotently, preserves the first context, fences dispatch, and obeys the outer transaction', function (): void {
    $configuration = coordinatorPostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');
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
        'connection' => 'tenant:1',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 3,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');
    $connection = $database->connection('integration_operations_test');
    assertCoordinatorTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    $notifier = new RecordingAcceptanceNotifier;
    app()->instance(DurableAcceptanceNotifier::class, $notifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $first = coordinatorCommand(['record' => 42], IntegrationContext::make('correlation:first', ['source' => 'initial']));
    $receipt = $coordinator->accept($first);

    expect($receipt->wasAlreadyRegistered)->toBeFalse()
        ->and($notifier->receipts)->toHaveCount(1)
        ->and($connection->table('integration_operations')->count())->toBe(1)
        ->and($connection->table('integration_operation_intents')->count())->toBe(1);

    /** @var OperationQuery $operationQuery */
    $operationQuery = app(OperationQuery::class);
    $queryCount = 0;
    $connection->listen(function () use (&$queryCount): void {
        $queryCount++;
    });
    $snapshotBatch = $operationQuery
        ->within(IntegrationScope::of('fixture_catalog', 'tenant:1'))
        ->findMany([$receipt->operationId, new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV')]);

    expect($queryCount)->toBe(1)
        ->and($snapshotBatch->snapshots())->toHaveCount(1)
        ->and($snapshotBatch->missingOperationIds())->toHaveCount(1)
        ->and($snapshotBatch->snapshots()[0]->operationId->equals($receipt->operationId))->toBeTrue()
        ->and($snapshotBatch->snapshots()[0]->context->equals($first->context))->toBeTrue()
        ->and($snapshotBatch->snapshots()[0]->resultAvailability)->toBe(ResultAvailability::NotReady)
        ->and($operationQuery
            ->within(IntegrationScope::of('fixture_catalog', 'tenant:foreign'))
            ->find($receipt->operationId))->toBeNull();

    $dispatchNotifier = new RecordingAcceptanceNotifier;
    $pendingDispatcher = new DatabasePendingOperationDispatcher(
        app(KernelDatabase::class),
        $dispatchNotifier,
        app(Repository::class),
    );
    $dispatchBatch = $pendingDispatcher->dispatch(
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        25,
    );
    $immediateRedispatch = $pendingDispatcher->dispatch(
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        25,
    );
    $dispatchedOperation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();

    expect($dispatchBatch->scanned)->toBe(1)
        ->and($dispatchBatch->dispatched)->toBe(1)
        ->and($dispatchBatch->failures())->toBe(0)
        ->and($immediateRedispatch->scanned)->toBe(0)
        ->and($dispatchNotifier->receipts)->toHaveCount(1)
        ->and($dispatchedOperation?->dispatch_attempts)->toBe(1)
        ->and($dispatchedOperation?->last_dispatched_at)->toBeString();

    Event::fake([OperationTerminalized::class]);
    app(OperationProcessor::class)->process($receipt->operationId);
    $completed = $operationQuery
        ->within(IntegrationScope::of('fixture_catalog', 'tenant:1'))
        ->find($receipt->operationId);

    expect($completed?->status)->toBe(OperationStatus::Succeeded)
        ->and($completed?->resultAvailability)->toBe(ResultAvailability::Available)
        ->and($completed?->result)->toEqual(new FakeOperationResult('executed'))
        ->and($connection->table('integration_operation_results')->count())->toBe(1)
        ->and($connection->table('integration_operation_attempts')->whereNotNull('finished_at')->count())->toBe(1);

    Event::assertDispatched(
        OperationTerminalized::class,
        fn (OperationTerminalized $event): bool => $event->operationId->equals($receipt->operationId)
            && $event->scope->equals($receipt->scope)
            && $event->status === OperationStatus::Succeeded,
    );

    /** @var ConsoleKernel $console */
    $console = app(ConsoleKernel::class);
    $showExitCode = $console->call('integration-operations:show', [
        'operation' => $receipt->operationId->value,
        '--provider' => 'fixture_catalog',
        '--connection' => 'tenant:1',
    ]);
    $showOutput = $console->output();
    $listExitCode = $console->call('integration-operations:list', [
        '--provider' => 'fixture_catalog',
        '--connection' => 'tenant:1',
        '--status' => OperationStatus::Succeeded->value,
    ]);
    $listOutput = $console->output();

    expect($showExitCode)->toBe(0)
        ->and($showOutput)->toContain($receipt->operationId->value, 'fixture_catalog.record.fetch', 'succeeded', 'available')
        ->and($showOutput)->not->toContain('executed', 'correlation:first')
        ->and($listExitCode)->toBe(0)
        ->and($listOutput)->toContain($receipt->operationId->value, 'fixture_catalog.record.fetch', 'succeeded')
        ->and($listOutput)->not->toContain('executed', 'correlation:first');

    $duplicate = coordinatorCommand(['record' => 42], IntegrationContext::make('correlation:changed', ['source' => 'later']));
    $duplicateReceipt = $coordinator->accept($duplicate);

    expect($duplicateReceipt->operationId->equals($receipt->operationId))->toBeTrue()
        ->and($duplicateReceipt->wasAlreadyRegistered)->toBeTrue()
        ->and($connection->table('integration_operations')->count())->toBe(1)
        ->and(decryptPersistedContext($connection, $receipt->operationId))->toBe($first->context->toArray());

    expect(fn () => $coordinator->accept(coordinatorCommand(
        ['record' => 43],
        IntegrationContext::make('correlation:first'),
    )))->toThrow(OperationIntentConflict::class)
        ->and($connection->table('integration_operations')->count())->toBe(1);

    config()->set('integration-operations.writer_fences.0.owner_mode', OwnerMode::Off->value);

    expect(fn () => $coordinator->accept(coordinatorCommand(
        ['record' => 44],
        IntegrationContext::make('correlation:off'),
        semanticSlot: 'off-fence',
    )))->toThrow(WriterFenceRejected::class)
        ->and($connection->table('integration_operations')->count())->toBe(1);

    config()->set('integration-operations.writer_fences.0.owner_mode', OwnerMode::ShadowRead->value);
    $connection->beginTransaction();

    try {
        $rollbackReceipt = $coordinator->accept(coordinatorCommand(
            ['record' => 45],
            IntegrationContext::make('correlation:rollback'),
            semanticSlot: 'rolled-back',
        ));

        expect($notifier->receipts)->toHaveCount(2)
            ->and($connection->table('integration_operations')->where('id', $rollbackReceipt->operationId->value)->exists())->toBeTrue();
    } finally {
        $connection->rollBack();
    }

    expect($notifier->receipts)->toHaveCount(2)
        ->and($connection->table('integration_operations')->count())->toBe(1);

    $connection->beginTransaction();
    $committedReceipt = $coordinator->accept(coordinatorCommand(
        ['record' => 46],
        IntegrationContext::make('correlation:commit'),
        semanticSlot: 'committed-outer',
    ));

    expect($notifier->receipts)->toHaveCount(2)
        ->and($connection->table('integration_operations')->where('id', $committedReceipt->operationId->value)->exists())->toBeTrue();

    $connection->commit();

    expect($notifier->receipts)->toHaveCount(3)
        ->and($connection->table('integration_operations')->count())->toBe(2);

    config()->set('database.connections.integration_operations_foreign_test', $configuration);
    $database->purge('integration_operations_foreign_test');
    $foreignConnection = $database->connection('integration_operations_foreign_test');
    $foreignConnection->beginTransaction();

    try {
        expect(fn () => $coordinator->accept(coordinatorCommand(
            ['record' => 47],
            IntegrationContext::make('correlation:foreign'),
            semanticSlot: 'foreign-transaction',
        )))->toThrow(CrossConnectionTransaction::class);
    } finally {
        $foreignConnection->rollBack();
    }

    expect($connection->table('integration_operations')->count())->toBe(2);

    $firstOperation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $connection->table('integration_operation_intents')
        ->where('id', $firstOperation?->intent_id)
        ->update([
            'current_operation_id' => $committedReceipt->operationId->value,
            'updated_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);

    expect(fn () => $coordinator->accept($first))->toThrow(OperationIntentConflict::class);
});

it('round robins due operation types within one priority and connection budget', function (): void {
    $configuration = coordinatorPostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');
    config()->set('integration-operations.queues.claim_batch_per_connection', 2);
    config()->set('integration-operations.queues.redispatch_after_seconds', 60);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');
    $connection = $database->connection('integration_operations_test');
    assertCoordinatorTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    insertFairnessWriterFence($connection, 'fixture_catalog.cost.sync');
    insertFairnessWriterFence($connection, 'fixture_catalog.invoice.issue');

    for ($index = 1; $index <= 6; $index++) {
        insertFairnessPendingOperation(
            $connection,
            $index,
            'fixture_catalog.cost.sync',
        );
    }

    insertFairnessPendingOperation(
        $connection,
        7,
        'fixture_catalog.invoice.issue',
    );

    $notifier = new RecordingAcceptanceNotifier;
    $dispatcher = new DatabasePendingOperationDispatcher(
        app(KernelDatabase::class),
        $notifier,
        app(Repository::class),
    );

    $batch = $dispatcher->dispatch(IntegrationScope::of('fixture_catalog', 'tenant:fairness'), 2);

    expect($batch->scanned)->toBe(2)
        ->and($batch->dispatched)->toBe(2)
        ->and(array_map(
            static fn (OperationReceipt $receipt): string => $receipt->operationType->value,
            $notifier->receipts,
        ))->toBe([
            'fixture_catalog.cost.sync',
            'fixture_catalog.invoice.issue',
        ]);
});

it('fails closed and restores the exact transaction baseline after a hostile writer-fence resolver', function (): void {
    $configuration = coordinatorPostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('database.connections.integration_operations_observer_test', $configuration);
    config()->set('database.connections.integration_operations_foreign_test', $configuration);
    config()->set('database.connections.integration_operations_late_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');
    $database->purge('integration_operations_observer_test');
    $database->purge('integration_operations_foreign_test');
    $connection = $database->connection('integration_operations_test');
    $observer = $database->connection('integration_operations_observer_test');
    $foreign = $database->connection('integration_operations_foreign_test');
    assertCoordinatorTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    $fence = new WriterFence(3, OwnerMode::On);
    $command = coordinatorCommand(
        ['record' => 70],
        IntegrationContext::make('correlation:hostile-resolver'),
        semanticSlot: 'hostile-resolver',
    );
    $opensKernelTransaction = coordinatorWithWriterFenceResolver(new CallbackWriterFenceResolver(
        $fence,
        function () use ($connection): void {
            $connection->beginTransaction();
        },
    ));

    expect(fn () => $opensKernelTransaction->accept($command))->toThrow(OperationPersistenceFailed::class)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($observer->table('integration_operations')->count())->toBe(0);

    $opensKernelTransactionThenThrows = coordinatorWithWriterFenceResolver(new CallbackWriterFenceResolver(
        $fence,
        function () use ($connection): void {
            $connection->beginTransaction();

            throw new WriterFenceRejected;
        },
    ));

    expect(fn () => $opensKernelTransactionThenThrows->accept($command))->toThrow(OperationPersistenceFailed::class)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($observer->table('integration_operations')->count())->toBe(0);

    $connection->beginTransaction();

    try {
        $opensNestedKernelTransaction = coordinatorWithWriterFenceResolver(new CallbackWriterFenceResolver(
            $fence,
            function () use ($connection): void {
                $connection->beginTransaction();
            },
        ));

        expect(fn () => $opensNestedKernelTransaction->accept($command))->toThrow(OperationPersistenceFailed::class)
            ->and($connection->transactionLevel())->toBe(1)
            ->and($connection->table('integration_operations')->count())->toBe(0);
    } finally {
        $connection->rollBack();
    }

    expect($connection->transactionLevel())->toBe(0)
        ->and($observer->table('integration_operations')->count())->toBe(0);

    $connection->beginTransaction();
    $commitsCallerTransaction = coordinatorWithWriterFenceResolver(new CallbackWriterFenceResolver(
        $fence,
        function () use ($connection): void {
            $connection->commit();
        },
    ));

    expect(fn () => $commitsCallerTransaction->accept($command))->toThrow(OperationPersistenceFailed::class)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($observer->table('integration_operations')->count())->toBe(0);

    $opensForeignTransaction = coordinatorWithWriterFenceResolver(new CallbackWriterFenceResolver(
        $fence,
        function () use ($foreign): void {
            $foreign->beginTransaction();
        },
    ));

    expect(fn () => $opensForeignTransaction->accept($command))->toThrow(OperationPersistenceFailed::class)
        ->and($foreign->transactionLevel())->toBe(0)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($observer->table('integration_operations')->count())->toBe(0);

    $resolvesNewConnection = coordinatorWithWriterFenceResolver(new CallbackWriterFenceResolver(
        $fence,
        function () use ($database): void {
            $database->connection('integration_operations_late_test');
        },
    ));

    expect(fn () => $resolvesNewConnection->accept($command))->toThrow(OperationPersistenceFailed::class)
        ->and($database->connection('integration_operations_late_test')->transactionLevel())->toBe(0)
        ->and($observer->table('integration_operations')->count())->toBe(0);
});

it('commits before durable notification and sanitizes notifier and reporter failures without leaking transactions', function (): void {
    $configuration = coordinatorPostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('database.connections.integration_operations_observer_test', $configuration);
    config()->set('database.connections.integration_operations_foreign_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_catalog',
        'connection' => 'tenant:1',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 3,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');
    $database->purge('integration_operations_observer_test');
    $database->purge('integration_operations_foreign_test');
    $connection = $database->connection('integration_operations_test');
    $observer = $database->connection('integration_operations_observer_test');
    $foreign = $database->connection('integration_operations_foreign_test');
    assertCoordinatorTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    $originalExceptionHandler = app(ExceptionHandler::class);
    $recordingExceptionHandler = new RecordingExceptionHandler($originalExceptionHandler);
    app()->instance(ExceptionHandler::class, $recordingExceptionHandler);
    $durableRowsObserved = 0;
    $sentinel = 'acceptance-notifier-secret-sentinel';
    $rollbackFailing = null;
    $normalCleanupConnection = null;

    try {
        $leakingNotifier = new CallbackDurableAcceptanceNotifier(
            function () use ($connection, $foreign, $observer, &$durableRowsObserved): void {
                $durableRowsObserved = $observer->table('integration_operations')->count();
                $connection->beginTransaction();
                $foreign->beginTransaction();
            },
        );
        $leakingCoordinator = coordinatorWithWriterFenceResolver(
            app(WriterFenceResolver::class),
            $leakingNotifier,
        );
        $firstReceipt = $leakingCoordinator->accept(coordinatorCommand(
            ['record' => 71],
            IntegrationContext::make('correlation:notifier-leak'),
            semanticSlot: 'notifier-leak',
        ));

        expect($durableRowsObserved)->toBe(1)
            ->and($observer->table('integration_operations')->where('id', $firstReceipt->operationId->value)->exists())->toBeTrue()
            ->and($connection->transactionLevel())->toBe(0)
            ->and($foreign->transactionLevel())->toBe(0)
            ->and($recordingExceptionHandler->reported)->toHaveCount(1)
            ->and($recordingExceptionHandler->reported[0])->toBeInstanceOf(DurableAcceptanceNotificationFailed::class);

        $throwingNotifier = new CallbackDurableAcceptanceNotifier(
            function () use ($connection, $foreign, $sentinel): void {
                $connection->beginTransaction();
                $foreign->beginTransaction();

                throw new RuntimeException($sentinel);
            },
        );
        $throwingCoordinator = coordinatorWithWriterFenceResolver(
            app(WriterFenceResolver::class),
            $throwingNotifier,
        );
        $secondReceipt = $throwingCoordinator->accept(coordinatorCommand(
            ['record' => 72],
            IntegrationContext::make('correlation:notifier-throw'),
            semanticSlot: 'notifier-throw',
        ));

        expect($observer->table('integration_operations')->where('id', $secondReceipt->operationId->value)->exists())->toBeTrue()
            ->and($connection->transactionLevel())->toBe(0)
            ->and($foreign->transactionLevel())->toBe(0)
            ->and($recordingExceptionHandler->reported)->toHaveCount(2)
            ->and((string) $recordingExceptionHandler->reported[1])->not->toContain($sentinel)
            ->and($recordingExceptionHandler->reported[1]->getPrevious())->toBeNull();

        $hostileReporter = new RecordingExceptionHandler(
            $originalExceptionHandler,
            function () use ($connection, $foreign): void {
                $connection->beginTransaction();
                $foreign->beginTransaction();

                throw new RuntimeException('reporter-secret-sentinel');
            },
        );
        app()->instance(ExceptionHandler::class, $hostileReporter);
        $thirdReceipt = $throwingCoordinator->accept(coordinatorCommand(
            ['record' => 73],
            IntegrationContext::make('correlation:reporter-throw'),
            semanticSlot: 'reporter-throw',
        ));

        expect($observer->table('integration_operations')->where('id', $thirdReceipt->operationId->value)->exists())->toBeTrue()
            ->and($connection->transactionLevel())->toBe(0)
            ->and($foreign->transactionLevel())->toBe(0)
            ->and($hostileReporter->reported)->toHaveCount(1)
            ->and($hostileReporter->reported[0])->toBeInstanceOf(DurableAcceptanceNotificationFailed::class);

        app()->instance(ExceptionHandler::class, $recordingExceptionHandler);
        config()->set('database.connections.integration_operations_notifier_cleanup_failing', [
            'driver' => 'acceptance_rollback_failing',
            'database' => 'acceptance-rollback-failing-fixture',
        ]);
        config()->set('database.connections.integration_operations_notifier_cleanup_normal', $configuration);
        $database->purge('integration_operations_notifier_cleanup_failing');
        $database->purge('integration_operations_notifier_cleanup_normal');
        $rollbackFailing = new RollbackFailingConnection($connection->getPdo());
        $database->extend(
            'acceptance_rollback_failing',
            static fn (): RollbackFailingConnection => $rollbackFailing,
        );
        $resolvedRollbackFailing = $database->connection('integration_operations_notifier_cleanup_failing');
        $normalCleanupConnection = $database->connection('integration_operations_notifier_cleanup_normal');
        $cleanupFailingNotifier = new CallbackDurableAcceptanceNotifier(
            function () use ($resolvedRollbackFailing, $normalCleanupConnection): void {
                $resolvedRollbackFailing->beginTransaction();
                $normalCleanupConnection->beginTransaction();
            },
        );
        $cleanupFailingCoordinator = coordinatorWithWriterFenceResolver(
            app(WriterFenceResolver::class),
            $cleanupFailingNotifier,
        );

        try {
            $cleanupFailingCoordinator->accept(coordinatorCommand(
                ['record' => 74],
                IntegrationContext::make('correlation:notifier-cleanup-failure'),
                semanticSlot: 'notifier-cleanup-failure',
            ));
            throw new LogicException('Expected durable notifier transaction cleanup to fail.');
        } catch (OperationPersistenceFailed $failure) {
            expect((string) $failure)->not->toContain('Sensitive rollback failure fixture.')
                ->and($failure->getPrevious())->toBeNull();
        }

        expect($observer->table('integration_operations')->where('semantic_slot', 'notifier-cleanup-failure')->exists())->toBeTrue()
            ->and($normalCleanupConnection->transactionLevel())->toBe(0);

        $rollbackFailing->resetAfterTest();
        $database->purge('integration_operations_notifier_cleanup_failing');
        $database->purge('integration_operations_notifier_cleanup_normal');
    } finally {
        app()->instance(ExceptionHandler::class, $originalExceptionHandler);

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }

        if ($foreign->transactionLevel() > 0) {
            $foreign->rollBack(0);
        }

        if ($normalCleanupConnection instanceof Connection && $normalCleanupConnection->transactionLevel() > 0) {
            $normalCleanupConnection->rollBack(0);
        }

        if ($rollbackFailing instanceof RollbackFailingConnection) {
            $rollbackFailing->resetAfterTest();
        }

        $database->purge('integration_operations_notifier_cleanup_failing');
        $database->purge('integration_operations_notifier_cleanup_normal');
    }
});

it('requires an explicit expected-current command to supersede a failed not-applied operation', function (): void {
    $configuration = coordinatorPostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_dispatch',
        'connection' => 'tenant:1',
        'operation_type' => 'fixture_dispatch.message.deliver',
        'generation' => 4,
        'owner_mode' => OwnerMode::On->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');
    $connection = $database->connection('integration_operations_test');
    assertCoordinatorTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    $definitions = new DefinitionRegistry;
    $definitions->register(FakeSingleEffectDefinitionProvider::class);
    $definitions->freeze(app(ContainerBindingInspector::class));
    $notifier = new RecordingAcceptanceNotifier;
    $coordinator = new DatabaseOperationCoordinator(
        app(KernelDatabase::class),
        $definitions,
        new ConfigLocalReferenceTypeRegistry(['fixture_resource', 'foreign_resource']),
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
    $missingReference = new AcceptOperation(
        scope: IntegrationScope::of('fixture_dispatch', 'tenant:1'),
        operationType: new OperationType('fixture_dispatch.message.deliver'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity('fixture_resource', 'default'),
        payload: new CanonicalObject(['message' => 'missing-reference']),
        context: IntegrationContext::make('correlation:dispatch'),
    );

    expect(fn () => $coordinator->accept($missingReference))->toThrow(LocalReferenceRequired::class)
        ->and($connection->table('integration_operation_intents')->count())->toBe(0)
        ->and($connection->table('integration_operations')->count())->toBe(0)
        ->and($notifier->receipts)->toHaveCount(0);

    $commandWithIdentity = static fn (IntentIdentity $identity): AcceptOperation => new AcceptOperation(
        scope: IntegrationScope::of('fixture_dispatch', 'tenant:1'),
        operationType: new OperationType('fixture_dispatch.message.deliver'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: $identity,
        payload: new CanonicalObject(['message' => 'invalid-identity']),
        context: IntegrationContext::make('correlation:dispatch'),
    );

    expect(fn () => $coordinator->accept($commandWithIdentity(new IntentIdentity(
        'foreign_resource',
        'default',
        new LocalReference('fixture_resource', 'resource:invalid'),
    ))))->toThrow(ManagedMutationIdentityRejected::class)
        ->and(fn () => $coordinator->accept($commandWithIdentity(new IntentIdentity(
            'fixture_resource',
            'default',
            new LocalReference('foreign_resource', 'resource:invalid'),
        ))))->toThrow(ManagedMutationIdentityRejected::class)
        ->and(fn () => $coordinator->accept($commandWithIdentity(new IntentIdentity(
            'fixture_resource',
            'undeclared',
            new LocalReference('fixture_resource', 'resource:invalid'),
        ))))->toThrow(ManagedMutationIdentityRejected::class)
        ->and($connection->table('integration_operation_intents')->count())->toBe(0)
        ->and($connection->table('integration_operations')->count())->toBe(0)
        ->and($notifier->receipts)->toHaveCount(0);

    $original = dispatchCoordinatorCommand(['message' => 'original']);
    $originalReceipt = $coordinator->accept($original);
    $duplicateReceipt = $coordinator->accept($original);

    expect($duplicateReceipt->operationId->equals($originalReceipt->operationId))->toBeTrue()
        ->and($duplicateReceipt->wasAlreadyRegistered)->toBeTrue()
        ->and($notifier->receipts)->toHaveCount(2);

    $connection->statement('ALTER TABLE integration_operations DISABLE TRIGGER io_operations_boundary_marker_once');

    try {
        $connection->table('integration_operations')
            ->where('id', $originalReceipt->operationId->value)
            ->update([
                'status' => 'failed',
                'disposition' => 'failed',
                'effect_state' => 'not_applied',
                'request_started_at' => $connection->raw('CURRENT_TIMESTAMP'),
                'last_safe_failure_code' => 'absent_conclusive',
                'last_safe_failure_summary' => 'The provider confirmed that no remote effect exists.',
                'completed_at' => $connection->raw('CURRENT_TIMESTAMP'),
                'updated_at' => $connection->raw('CURRENT_TIMESTAMP'),
            ]);
    } finally {
        $connection->statement('ALTER TABLE integration_operations ENABLE TRIGGER io_operations_boundary_marker_once');
    }
    $corrected = dispatchCoordinatorCommand(['message' => 'corrected']);

    expect(fn () => $coordinator->accept($corrected))->toThrow(OperationIntentConflict::class)
        ->and($connection->table('integration_operations')->count())->toBe(1)
        ->and(fn () => $coordinator->supersedeFailed(new SupersedeFailedOperation(
            new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
            $corrected,
        )))->toThrow(OperationIntentConflict::class)
        ->and(fn () => $coordinator->supersedeFailed(new SupersedeFailedOperation(
            $originalReceipt->operationId,
            $original,
        )))->toThrow(OperationIntentConflict::class)
        ->and($connection->table('integration_operations')->count())->toBe(1);

    $connection->beginTransaction();

    try {
        $connection->table('integration_operation_results')->insert([
            'operation_id' => $originalReceipt->operationId->value,
            'result_type' => 'fixture.operation_result',
            'result_schema_version' => 1,
            'result_key_version' => 1,
            'result_cipher' => 'AES-256-GCM',
            'result_ciphertext' => 'unexpected-result',
            'result_ciphertext_sha256' => str_repeat('f', 64),
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);

        expect(fn () => $coordinator->supersedeFailed(new SupersedeFailedOperation(
            $originalReceipt->operationId,
            $corrected,
        )))->toThrow(OperationIntentConflict::class);
    } finally {
        $connection->rollBack();
    }

    $supersedeActorReference = 'operator:supervisor-42';
    $supersedingReceipt = $coordinator->supersedeFailed(new SupersedeFailedOperation(
        $originalReceipt->operationId,
        $corrected,
        new OperationActor('operator', $supersedeActorReference),
    ));
    $currentIntent = $connection->table('integration_operation_intents')->first();
    $supersedingOperation = $connection->table('integration_operations')
        ->where('id', $supersedingReceipt->operationId->value)
        ->first();

    expect($supersedingReceipt->operationId->equals($originalReceipt->operationId))->toBeFalse()
        ->and($connection->table('integration_operations')->count())->toBe(2)
        ->and($currentIntent?->current_generation)->toBe(2)
        ->and($currentIntent?->current_operation_id)->toBe($supersedingReceipt->operationId->value)
        ->and($supersedingOperation?->supersedes_operation_id)->toBe($originalReceipt->operationId->value)
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $supersedingReceipt->operationId->value)->value('reason_code'))->toBe('superseded_failed')
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $supersedingReceipt->operationId->value)->value('actor_category'))->toBe('operator')
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $supersedingReceipt->operationId->value)->value('actor_reference_hmac'))->not->toBeNull()
        ->and(json_encode($connection->table('integration_operation_transitions')->where('operation_id', $supersedingReceipt->operationId->value)->first(), JSON_THROW_ON_ERROR))->not->toContain($supersedeActorReference)
        ->and($notifier->receipts)->toHaveCount(3);

    $secondaryReceipt = $coordinator->accept(dispatchCoordinatorCommand(
        ['message' => 'secondary-effect'],
        semanticSlot: 'secondary',
    ));

    expect($secondaryReceipt->wasAlreadyRegistered)->toBeFalse()
        ->and($secondaryReceipt->operationId->equals($supersedingReceipt->operationId))->toBeFalse()
        ->and($connection->table('integration_operation_intents')->count())->toBe(2)
        ->and($connection->table('integration_operations')->count())->toBe(3)
        ->and($notifier->receipts)->toHaveCount(4);
});

it('backfills intent and local-reference aliases across key rotation and rejects a mismatched alias', function (): void {
    $configuration = coordinatorPostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.local_references.allowed_types', ['catalog_record']);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_catalog',
        'connection' => 'tenant:1',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 3,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');
    $connection = $database->connection('integration_operations_test');
    assertCoordinatorTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $command = localReferenceCoordinatorCommand();
    $receipt = $coordinator->accept($command);
    $operation = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->first();
    $intentId = $operation?->intent_id;

    expect($connection->table('integration_operation_lookup_keys')
        ->where('subject_id', $intentId)
        ->whereIn('lookup_type', ['intent', 'local_reference'])
        ->count())->toBe(2);

    config()->set('integration-operations.hmac.active_version', 2);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
        2 => 'base64:'.base64_encode(str_repeat('i', 32)),
    ]);
    app()->forgetInstance(OperationCoordinator::class);
    app()->forgetInstance(LookupHmacKeyRing::class);
    app()->forgetInstance(HmacSha256::class);
    /** @var OperationCoordinator $rotatedCoordinator */
    $rotatedCoordinator = app(OperationCoordinator::class);
    $rotatedReceipt = $rotatedCoordinator->accept($command);

    expect($rotatedReceipt->operationId->equals($receipt->operationId))->toBeTrue();

    foreach (['intent', 'local_reference'] as $lookupType) {
        expect($connection->table('integration_operation_lookup_keys')
            ->where('subject_id', $intentId)
            ->where('lookup_type', $lookupType)
            ->orderBy('key_version')
            ->pluck('key_version')
            ->all())->toBe([1, 2]);
    }

    $connection->table('integration_operation_lookup_keys')->insert([
        'id' => app(UlidFactory::class)->generate()->value,
        'provider' => 'fixture_catalog',
        'connection_key' => 'tenant:1',
        'lookup_type' => 'local_reference',
        'subject_id' => $intentId,
        'intent_id' => $intentId,
        'operation_id' => null,
        'key_version' => 3,
        'digest' => str_repeat('0', 64),
        'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
    ]);
    config()->set('integration-operations.hmac.active_version', 3);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
        2 => 'base64:'.base64_encode(str_repeat('i', 32)),
        3 => 'base64:'.base64_encode(str_repeat('j', 32)),
    ]);
    app()->forgetInstance(OperationCoordinator::class);
    app()->forgetInstance(LookupHmacKeyRing::class);
    app()->forgetInstance(HmacSha256::class);
    /** @var OperationCoordinator $mismatchedCoordinator */
    $mismatchedCoordinator = app(OperationCoordinator::class);

    expect(fn () => $mismatchedCoordinator->accept($command))->toThrow(OperationIntentConflict::class);
});

it('converges concurrent accepts from separate PostgreSQL connections on one intent and operation', function (): void {
    if (! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')
        || ! function_exists('posix_kill')) {
        Assert::markTestSkipped('The concurrent PostgreSQL gate requires the pcntl and posix extensions.');
    }

    $configuration = coordinatorPostgresConfiguration();
    config()->set('database.connections.integration_operations_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_test');
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
    ]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.cipher', 'AES-256-GCM');
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);
    config()->set('integration-operations.writer_fences', [[
        'provider' => 'fixture_catalog',
        'connection' => 'tenant:concurrent',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 1,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_test');
    $connection = $database->connection('integration_operations_test');
    assertCoordinatorTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    $command = new AcceptOperation(
        scope: IntegrationScope::of('fixture_catalog', 'tenant:concurrent'),
        operationType: new OperationType('fixture_catalog.record.fetch'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity('catalog_record', 'concurrent'),
        payload: new CanonicalObject(['record' => 91]),
        context: IntegrationContext::make('correlation:concurrent'),
    );
    $temporaryPrefix = sys_get_temp_dir().'/integration-operations-'.bin2hex(random_bytes(8));
    $startFile = "{$temporaryPrefix}.start";
    $resultFiles = ["{$temporaryPrefix}.first", "{$temporaryPrefix}.second"];
    $children = [];
    $remainingChildren = [];
    $database->disconnect('integration_operations_test');

    try {
        foreach ($resultFiles as $resultFile) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork the PostgreSQL concurrency test process.');
            }

            if ($pid === 0) {
                try {
                    $deadline = microtime(true) + 10;

                    while (! is_file($startFile)) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Concurrent accept start barrier timed out.');
                        }

                        usleep(1000);
                    }

                    $database->purge('integration_operations_test');
                    app()->forgetInstance(OperationCoordinator::class);
                    $childReceipt = app(OperationCoordinator::class)->accept($command);
                    file_put_contents($resultFile, $childReceipt->operationId->value);
                    exit(0);
                } catch (Throwable $failure) {
                    file_put_contents($resultFile, 'ERROR:'.$failure::class);
                    exit(1);
                }
            }

            $children[] = $pid;
            $remainingChildren[$pid] = true;
        }

        file_put_contents($startFile, 'go');
        $exitCodes = [];
        $deadline = microtime(true) + 15;

        while (true) {
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
                throw new RuntimeException('Concurrent accept workers exceeded the test timeout.');
            }

            usleep(1000);
        }

        $receipts = array_map(static fn (string $path): string|false => file_get_contents($path), $resultFiles);

        expect(array_values($exitCodes))->toBe([0, 0])
            ->and($receipts[0])->toBeString()
            ->and($receipts[1])->toBe($receipts[0]);
    } finally {
        foreach (array_keys($remainingChildren) as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        foreach ([$startFile, ...$resultFiles] as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    $database->purge('integration_operations_test');
    $connection = $database->connection('integration_operations_test');

    expect($connection->table('integration_operation_intents')->count())->toBe(1)
        ->and($connection->table('integration_operations')->count())->toBe(1);
});

/** @param array<string, mixed> $payload */
function coordinatorCommand(
    array $payload,
    IntegrationContext $context,
    string $semanticSlot = 'default',
): AcceptOperation {
    return new AcceptOperation(
        scope: IntegrationScope::of('fixture_catalog', 'tenant:1'),
        operationType: new OperationType('fixture_catalog.record.fetch'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity('catalog_record', $semanticSlot),
        payload: new CanonicalObject($payload),
        context: $context,
    );
}

/** @param array<string, mixed> $payload */
function dispatchCoordinatorCommand(array $payload, string $semanticSlot = 'default'): AcceptOperation
{
    return new AcceptOperation(
        scope: IntegrationScope::of('fixture_dispatch', 'tenant:1'),
        operationType: new OperationType('fixture_dispatch.message.deliver'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            $semanticSlot,
            new LocalReference('fixture_resource', 'resource:message'),
        ),
        payload: new CanonicalObject($payload),
        context: IntegrationContext::make('correlation:dispatch'),
    );
}

function localReferenceCoordinatorCommand(): AcceptOperation
{
    return new AcceptOperation(
        scope: IntegrationScope::of('fixture_catalog', 'tenant:1'),
        operationType: new OperationType('fixture_catalog.record.fetch'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'catalog_record',
            'local-reference',
            new LocalReference('catalog_record', 'record:42'),
        ),
        payload: new CanonicalObject(['record' => 42]),
        context: IntegrationContext::make('correlation:local-reference'),
    );
}

function coordinatorWithWriterFenceResolver(
    WriterFenceResolver $writerFences,
    ?DurableAcceptanceNotifier $notifier = null,
): DatabaseOperationCoordinator {
    return new DatabaseOperationCoordinator(
        app(KernelDatabase::class),
        app(DefinitionRegistry::class),
        new ConfigLocalReferenceTypeRegistry([]),
        $writerFences,
        app(DatabaseWriterFenceAuthority::class),
        app(LookupHmacKeyRing::class),
        app(HmacSha256::class),
        app(CanonicalJsonV1::class),
        app(BoundPayloadEnvelopeCodec::class),
        app(UlidFactory::class),
        app(OperationStateMachine::class),
        $notifier ?? new RecordingAcceptanceNotifier,
        app(Repository::class),
    );
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function coordinatorPostgresConfiguration(): array
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

function assertCoordinatorTestDatabase(Connection $connection, string $configuredDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass || ! is_string($current->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the coordinator PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase($configuredDatabase, $current->database_name);
}

function insertFairnessPendingOperation(Connection $connection, int $sequence, string $operationType): void
{
    $intentId = fairnessCoordinatorId($sequence * 2);
    $operationId = fairnessCoordinatorId(($sequence * 2) + 1);
    $semanticSlot = "fairness-{$sequence}";
    $intentKey = hash('sha256', "fairness-intent-{$sequence}");
    $acceptedAt = sprintf('2026-08-27 12:00:00.%06d+00', $sequence);

    $connection->table('integration_operation_intents')->insert([
        'id' => $intentId,
        'provider' => 'fixture_catalog',
        'connection_key' => 'tenant:fairness',
        'operation_type' => $operationType,
        'resource_type' => 'invoice',
        'semantic_slot' => $semanticSlot,
        'intent_key_hmac' => $intentKey,
        'hmac_key_version' => 1,
        'current_generation' => 1,
        'current_operation_id' => $operationId,
        'created_at' => $acceptedAt,
        'updated_at' => $acceptedAt,
    ]);
    $connection->table('integration_operations')->insert([
        'id' => $operationId,
        'intent_id' => $intentId,
        'intent_generation' => 1,
        'provider' => 'fixture_catalog',
        'connection_key' => 'tenant:fairness',
        'operation_type' => $operationType,
        'resource_type' => 'invoice',
        'semantic_slot' => $semanticSlot,
        'intent_key_hmac' => $intentKey,
        'current_payload_revision' => 1,
        'payload_schema_version' => 1,
        'handler_version' => 1,
        'result_schema_version' => 1,
        'max_remote_writes' => 0,
        'status' => OperationStatus::Pending->value,
        'disposition' => 'in_progress',
        'effect_state' => 'not_started',
        'row_version' => 1,
        'priority' => 0,
        'attempts' => 0,
        'reconcile_attempts' => 0,
        'dispatch_attempts' => 0,
        'writer_generation' => 1,
        'owner_mode_at_accept' => OwnerMode::ShadowRead->value,
        'accepted_at' => $acceptedAt,
        'created_at' => $acceptedAt,
        'updated_at' => $acceptedAt,
    ]);
}

function insertFairnessWriterFence(Connection $connection, string $operationType): void
{
    $connection->table('integration_operation_writer_fences')->insert([
        'provider' => 'fixture_catalog',
        'connection_key' => 'tenant:fairness',
        'operation_type' => $operationType,
        'generation' => 1,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort_bound' => false,
        'epoch' => 1,
        'created_at' => '2026-08-27 12:00:00+00',
        'updated_at' => '2026-08-27 12:00:00+00',
    ]);
}

function fairnessCoordinatorId(int $sequence): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    return '01ARZ3NDEKTSV4RRFFQ69G5FA'.$alphabet[$sequence];
}

/** @return array{version: int, correlation_id: string|null, attributes: array<string, bool|int|string|null>} */
function decryptPersistedContext(Connection $connection, OperationId $operationId): array
{
    $payload = $connection->table('integration_operation_payloads')
        ->where('operation_id', $operationId->value)
        ->first();

    if (! $payload instanceof stdClass
        || ! is_int($payload->context_key_version ?? null)
        || ! is_string($payload->context_cipher ?? null)
        || ! is_string($payload->context_ciphertext ?? null)
        || ! is_string($payload->context_ciphertext_sha256 ?? null)) {
        throw new RuntimeException('The persisted coordinator context is invalid.');
    }

    $decoded = app(BoundPayloadEnvelopeCodec::class)->decrypt(
        new EncryptedEnvelope(
            $payload->context_key_version,
            $payload->context_cipher,
            $payload->context_ciphertext,
            new Sha256Digest($payload->context_ciphertext_sha256),
        ),
        new PayloadEnvelopeBinding('context', $operationId, 1, IntegrationContext::Version),
    )->values;

    if (! isset($decoded['version'], $decoded['attributes'])
        || $decoded['version'] !== IntegrationContext::Version
        || ! is_array($decoded['attributes'])
        || (! is_string($decoded['correlation_id'] ?? null) && ($decoded['correlation_id'] ?? null) !== null)) {
        throw new RuntimeException('The decrypted coordinator context is invalid.');
    }

    return [
        'version' => $decoded['version'],
        'correlation_id' => $decoded['correlation_id'],
        'attributes' => $decoded['attributes'],
    ];
}
