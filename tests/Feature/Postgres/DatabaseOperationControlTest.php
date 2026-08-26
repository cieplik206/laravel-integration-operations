<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextCodec;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\PayloadCipher;
use Cieplik206\IntegrationOperations\Contracts\PayloadEncryptionKeyRing;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Exceptions\OperationControlConflict;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationControl;
use Cieplik206\IntegrationOperations\Runtime\OperationStateMachine;
use Cieplik206\IntegrationOperations\Tests\Support\CallbackPayloadCipher;
use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingExceptionHandler;
use Cieplik206\IntegrationOperations\Tests\Support\RejectingManualOperationResolver;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\CancelOperation;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\OperationActor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\ReplacePendingOperation;
use Cieplik206\IntegrationOperations\ValueObjects\Sha256Digest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Assert;

it('appends an explicit pending payload revision and cancels only before any effect', function (): void {
    $configuration = operationControlPostgresConfiguration();
    config()->set('database.connections.integration_operations_control_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_control_test');
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
        'connection' => 'tenant:control',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 1,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_control_test');
    $connection = $database->connection('integration_operations_control_test');
    assertOperationControlTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_control_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $accepted = $coordinator->accept(operationControlAcceptCommand(['record' => 1]));
    $withoutCorrelation = $coordinator->accept(operationControlAcceptCommand(
        ['record' => 100],
        IntegrationContext::make(),
        'ghost-correlation',
    ));
    $lookupAliasesBefore = $connection->table('integration_operation_lookup_keys')
        ->where('operation_id', $accepted->operationId->value)
        ->orderBy('lookup_type')
        ->orderBy('key_version')
        ->get(['lookup_type', 'key_version', 'digest'])
        ->map(static fn (stdClass $alias): array => (array) $alias)
        ->all();
    config()->set('integration-operations.encryption.active_version', 2);
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
        2 => 'base64:'.base64_encode(str_repeat('f', 32)),
    ]);
    app()->forgetInstance(BoundPayloadEnvelopeCodec::class);
    app()->forgetInstance(PayloadCipher::class);
    app()->forgetInstance(PayloadEncryptionKeyRing::class);
    config()->set('integration-operations.hmac.active_version', 2);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
        2 => 'base64:'.base64_encode(str_repeat('i', 32)),
    ]);
    app()->forgetInstance(LookupHmacKeyRing::class);
    app()->forgetInstance(HmacSha256::class);
    $manualResolver = new RejectingManualOperationResolver;
    $control = new DatabaseOperationControl(
        app(KernelDatabase::class),
        app(DefinitionRegistry::class),
        app(LookupHmacKeyRing::class),
        app(HmacSha256::class),
        app(CanonicalJsonV1::class),
        app(IntegrationContextCodec::class),
        app(BoundPayloadEnvelopeCodec::class),
        app(UlidFactory::class),
        app(OperationStateMachine::class),
        $manualResolver,
        app(Repository::class),
    );
    $actor = new OperationActor('operator', 'user:42');
    $replacement = new ReplacePendingOperation(
        IntegrationScope::of('fixture_catalog', 'tenant:control'),
        $accepted->operationId,
        1,
        new CanonicalObject(['record' => 2]),
        $actor,
    );

    $hostileControl = new DatabaseOperationControl(
        app(KernelDatabase::class),
        app(DefinitionRegistry::class),
        app(LookupHmacKeyRing::class),
        app(HmacSha256::class),
        app(CanonicalJsonV1::class),
        app(IntegrationContextCodec::class),
        new BoundPayloadEnvelopeCodec(
            new CallbackPayloadCipher(
                app(PayloadCipher::class),
                function () use ($connection): void {
                    $connection->beginTransaction();
                },
            ),
            app(CanonicalJsonV1::class),
        ),
        app(UlidFactory::class),
        app(OperationStateMachine::class),
        $manualResolver,
        app(Repository::class),
    );

    expect(fn () => $hostileControl->replacePending($replacement))->toThrow(OperationControlConflict::class)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->table('integration_operations')->where('id', $accepted->operationId->value)->value('current_payload_revision'))->toBe(1);

    $connection->beginTransaction();

    try {
        $nestedHostileControl = new DatabaseOperationControl(
            app(KernelDatabase::class),
            app(DefinitionRegistry::class),
            app(LookupHmacKeyRing::class),
            app(HmacSha256::class),
            app(CanonicalJsonV1::class),
            app(IntegrationContextCodec::class),
            new BoundPayloadEnvelopeCodec(
                new CallbackPayloadCipher(
                    app(PayloadCipher::class),
                    function () use ($connection): void {
                        $connection->beginTransaction();
                    },
                ),
                app(CanonicalJsonV1::class),
            ),
            app(UlidFactory::class),
            app(OperationStateMachine::class),
            $manualResolver,
            app(Repository::class),
        );

        expect(fn () => $nestedHostileControl->replacePending($replacement))->toThrow(OperationControlConflict::class)
            ->and($connection->transactionLevel())->toBe(1)
            ->and($connection->table('integration_operations')->where('id', $accepted->operationId->value)->value('current_payload_revision'))->toBe(1);
    } finally {
        $connection->rollBack();
    }

    $queryFailureSentinel = 'replace-query-ciphertext-secret-sentinel';
    $originalExceptionHandler = app(ExceptionHandler::class);
    config()->set('database.connections.integration_operations_control_reporter_foreign', $configuration);
    $database->purge('integration_operations_control_reporter_foreign');
    $reporterForeign = $database->connection('integration_operations_control_reporter_foreign');
    $recordingExceptionHandler = new RecordingExceptionHandler(
        $originalExceptionHandler,
        function () use ($connection, $reporterForeign): void {
            $connection->beginTransaction();
            $reporterForeign->beginTransaction();

            throw new RuntimeException('replace-reporter-secret-sentinel');
        },
    );
    app()->instance(ExceptionHandler::class, $recordingExceptionHandler);
    $connection->unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION io_test_fail_replacement_insert() RETURNS trigger AS \$\$
        BEGIN
            RAISE EXCEPTION '{$queryFailureSentinel}' USING ERRCODE = '40001';
        END;
        \$\$ LANGUAGE plpgsql
        SQL);
    $connection->unprepared(<<<'SQL'
        CREATE TRIGGER io_test_replacement_insert_failure
        BEFORE INSERT ON integration_operation_payloads
        FOR EACH ROW EXECUTE FUNCTION io_test_fail_replacement_insert()
        SQL);

    try {
        try {
            $control->replacePending($replacement);
            throw new LogicException('Expected replacement persistence to fail.');
        } catch (OperationPersistenceFailed $failure) {
            expect((string) $failure)->not->toContain($queryFailureSentinel)
                ->and($failure->getPrevious())->toBeNull();
        }

        expect($recordingExceptionHandler->reported)->toHaveCount(1)
            ->and($recordingExceptionHandler->reported[0])->toBeInstanceOf(OperationPersistenceFailed::class)
            ->and((string) $recordingExceptionHandler->reported[0])->not->toContain($queryFailureSentinel)
            ->and($recordingExceptionHandler->reported[0]->getPrevious())->toBeNull()
            ->and($connection->transactionLevel())->toBe(0)
            ->and($reporterForeign->transactionLevel())->toBe(0)
            ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->count())->toBe(1);
    } finally {
        app()->instance(ExceptionHandler::class, $originalExceptionHandler);

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack(0);
        }

        if ($reporterForeign->transactionLevel() > 0) {
            $reporterForeign->rollBack(0);
        }

        $connection->unprepared('DROP TRIGGER IF EXISTS io_test_replacement_insert_failure ON integration_operation_payloads');
        $connection->unprepared('DROP FUNCTION IF EXISTS io_test_fail_replacement_insert()');
        $database->purge('integration_operations_control_reporter_foreign');
    }

    foreach (['payload_fingerprint_hmac', 'context_lookup_hmac', 'correlation_id_hmac'] as $corruptedColumn) {
        $connection->beginTransaction();

        try {
            $connection->statement('ALTER TABLE integration_operation_payloads DISABLE TRIGGER io_payloads_semantic_immutable');
            $connection->table('integration_operation_payloads')
                ->where('operation_id', $accepted->operationId->value)
                ->where('payload_revision', 1)
                ->update([$corruptedColumn => str_repeat('0', 64)]);
            $connection->statement('ALTER TABLE integration_operation_payloads ENABLE TRIGGER io_payloads_semantic_immutable');

            expect(fn () => $control->replacePending($replacement))->toThrow(OperationControlConflict::class);
        } finally {
            $connection->rollBack();
        }
    }

    foreach ([
        'payload_ciphertext' => 'corrupt-payload-ciphertext',
        'context_ciphertext' => 'corrupt-context-ciphertext',
        'payload_ciphertext_sha256' => str_repeat('0', 64),
        'context_ciphertext_sha256' => str_repeat('0', 64),
        'payload_schema_version' => 2,
        'context_schema_version' => 2,
    ] as $corruptedColumn => $corruptedValue) {
        $connection->beginTransaction();

        try {
            $connection->statement('ALTER TABLE integration_operation_payloads DISABLE TRIGGER io_payloads_semantic_immutable');
            $connection->table('integration_operation_payloads')
                ->where('operation_id', $accepted->operationId->value)
                ->where('payload_revision', 1)
                ->update([$corruptedColumn => $corruptedValue]);
            $connection->statement('ALTER TABLE integration_operation_payloads ENABLE TRIGGER io_payloads_semantic_immutable');

            expect(fn () => $control->replacePending($replacement))->toThrow(OperationControlConflict::class);
        } finally {
            $connection->rollBack();
        }
    }

    $connection->beginTransaction();

    try {
        $connection->statement('ALTER TABLE integration_operation_lookup_keys DISABLE TRIGGER io_lookup_alias_immutable');
        $connection->table('integration_operation_lookup_keys')
            ->where('operation_id', $accepted->operationId->value)
            ->where('lookup_type', 'context')
            ->where('key_version', 1)
            ->update(['digest' => str_repeat('0', 64)]);
        $connection->statement('ALTER TABLE integration_operation_lookup_keys ENABLE TRIGGER io_lookup_alias_immutable');

        expect(fn () => $control->replacePending($replacement))->toThrow(OperationControlConflict::class);
    } finally {
        $connection->rollBack();
    }

    $connection->table('integration_operation_lookup_keys')->insert([
        'id' => app(UlidFactory::class)->generate()->value,
        'provider' => 'fixture_catalog',
        'connection_key' => 'tenant:control',
        'lookup_type' => 'correlation',
        'subject_id' => $withoutCorrelation->operationId->value,
        'intent_id' => null,
        'operation_id' => $withoutCorrelation->operationId->value,
        'key_version' => 1,
        'digest' => str_repeat('0', 64),
        'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
    ]);

    expect(fn () => $control->replacePending(new ReplacePendingOperation(
        IntegrationScope::of('fixture_catalog', 'tenant:control'),
        $withoutCorrelation->operationId,
        1,
        new CanonicalObject(['record' => 101]),
    )))->toThrow(OperationControlConflict::class);

    $connection->beginTransaction();

    try {
        $control->replacePending($replacement);

        expect($connection->table('integration_operations')->where('id', $accepted->operationId->value)->value('current_payload_revision'))->toBe(2)
            ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->count())->toBe(2)
            ->and($connection->table('integration_operation_transitions')->where('operation_id', $accepted->operationId->value)->count())->toBe(2);
    } finally {
        $connection->rollBack();
    }

    expect($connection->table('integration_operations')->where('id', $accepted->operationId->value)->value('current_payload_revision'))->toBe(1)
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->count())->toBe(1)
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $accepted->operationId->value)->count())->toBe(1)
        ->and($connection->table('integration_operation_lookup_keys')->where('operation_id', $accepted->operationId->value)->where('key_version', 2)->doesntExist())->toBeTrue();

    $replaced = $control->replacePending($replacement);
    $operation = $connection->table('integration_operations')->where('id', $accepted->operationId->value)->first();
    $lookupAliasesAfter = $connection->table('integration_operation_lookup_keys')
        ->where('operation_id', $accepted->operationId->value)
        ->orderBy('lookup_type')
        ->orderBy('key_version')
        ->get(['lookup_type', 'key_version', 'digest'])
        ->map(static fn (stdClass $alias): array => (array) $alias)
        ->all();

    foreach ($lookupAliasesBefore as $historicalAlias) {
        expect($lookupAliasesAfter)->toContain($historicalAlias);
    }

    expect($replaced->operationId->equals($accepted->operationId))->toBeTrue()
        ->and($replaced->wasAlreadyRegistered)->toBeFalse()
        ->and($operation?->current_payload_revision)->toBe(2)
        ->and($operation?->row_version)->toBe(2)
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->count())->toBe(2)
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $accepted->operationId->value)->count())->toBe(2)
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $accepted->operationId->value)->orderByDesc('sequence')->value('reason_code'))->toBe('payload_replaced_pending')
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $accepted->operationId->value)->orderByDesc('sequence')->value('actor_reference_hmac'))->not->toBeNull()
        ->and($connection->table('integration_operation_lookup_keys')->where('operation_id', $accepted->operationId->value)->where('key_version', 2)->count())->toBe(2)
        ->and(decryptOperationControlContext($connection, $accepted->operationId, 1))->toBe(decryptOperationControlContext($connection, $accepted->operationId, 2))
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->where('payload_revision', 1)->value('context_key_version'))->toBe(1)
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->where('payload_revision', 2)->value('context_key_version'))->toBe(2)
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->where('payload_revision', 2)->value('hmac_key_version'))->toBe(2)
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->where('payload_revision', 2)->value('context_lookup_hmac'))->toBe(
            $connection->table('integration_operation_lookup_keys')->where('operation_id', $accepted->operationId->value)->where('lookup_type', 'context')->where('key_version', 2)->value('digest'),
        )
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->where('payload_revision', 2)->value('correlation_id_hmac'))->toBe(
            $connection->table('integration_operation_lookup_keys')->where('operation_id', $accepted->operationId->value)->where('lookup_type', 'correlation')->where('key_version', 2)->value('digest'),
        );

    $samePayload = new ReplacePendingOperation(
        IntegrationScope::of('fixture_catalog', 'tenant:control'),
        $accepted->operationId,
        2,
        new CanonicalObject(['record' => 2]),
        $actor,
    );
    $aliasCountBeforeIdempotentReplace = $connection->table('integration_operation_lookup_keys')
        ->where('operation_id', $accepted->operationId->value)
        ->count();
    $sameReceipt = $control->replacePending($samePayload);

    expect($sameReceipt->wasAlreadyRegistered)->toBeTrue()
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->count())->toBe(2)
        ->and($connection->table('integration_operation_lookup_keys')->where('operation_id', $accepted->operationId->value)->count())->toBe($aliasCountBeforeIdempotentReplace)
        ->and(fn () => $control->replacePending($replacement))->toThrow(OperationControlConflict::class);

    $connection->beginTransaction();

    try {
        $connection->table('integration_operation_lookup_keys')
            ->where('operation_id', $accepted->operationId->value)
            ->where('lookup_type', 'context')
            ->where('key_version', 2)
            ->update(['retired_at' => $connection->raw('CURRENT_TIMESTAMP')]);

        expect(fn () => $control->replacePending(new ReplacePendingOperation(
            IntegrationScope::of('fixture_catalog', 'tenant:control'),
            $accepted->operationId,
            2,
            new CanonicalObject(['record' => 3]),
            $actor,
        )))->toThrow(OperationControlConflict::class);
    } finally {
        $connection->rollBack();
    }

    config()->set('integration-operations.hmac.keys', [
        2 => 'base64:'.base64_encode(str_repeat('i', 32)),
    ]);
    app()->forgetInstance(LookupHmacKeyRing::class);
    app()->forgetInstance(HmacSha256::class);
    $retiredKeyControl = new DatabaseOperationControl(
        app(KernelDatabase::class),
        app(DefinitionRegistry::class),
        app(LookupHmacKeyRing::class),
        app(HmacSha256::class),
        app(CanonicalJsonV1::class),
        app(IntegrationContextCodec::class),
        app(BoundPayloadEnvelopeCodec::class),
        app(UlidFactory::class),
        app(OperationStateMachine::class),
        $manualResolver,
        app(Repository::class),
    );
    $retiredKeyControl->replacePending(new ReplacePendingOperation(
        IntegrationScope::of('fixture_catalog', 'tenant:control'),
        $accepted->operationId,
        2,
        new CanonicalObject(['record' => 3]),
        $actor,
    ));

    expect($connection->table('integration_operations')->where('id', $accepted->operationId->value)->value('current_payload_revision'))->toBe(3)
        ->and($connection->table('integration_operation_lookup_keys')->where('operation_id', $accepted->operationId->value)->where('key_version', 1)->count())->toBe(2)
        ->and(decryptOperationControlContext($connection, $accepted->operationId, 3))->toBe(decryptOperationControlContext($connection, $accepted->operationId, 1));

    $connection->beginTransaction();

    try {
        $connection->table('integration_operation_results')->insert([
            'operation_id' => $accepted->operationId->value,
            'result_type' => 'fixture.operation_result',
            'result_schema_version' => 1,
            'result_key_version' => 1,
            'result_cipher' => 'AES-256-GCM',
            'result_ciphertext' => 'unexpected-result',
            'result_ciphertext_sha256' => str_repeat('f', 64),
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);

        expect(fn () => $control->cancel(new CancelOperation(
            IntegrationScope::of('fixture_catalog', 'tenant:control'),
            $accepted->operationId,
            'must_fail_closed_for_unexpected_result',
            $actor,
        )))->toThrow(OperationControlConflict::class);
    } finally {
        $connection->rollBack();
    }

    $cancelled = $control->cancel(new CancelOperation(
        IntegrationScope::of('fixture_catalog', 'tenant:control'),
        $accepted->operationId,
        'operator_cancelled_before_effect',
        $actor,
    ));
    $terminal = $connection->table('integration_operations')->where('id', $accepted->operationId->value)->first();

    expect($cancelled->operationId->equals($accepted->operationId))->toBeTrue()
        ->and($terminal?->status)->toBe('cancelled')
        ->and($terminal?->disposition)->toBe('cancelled')
        ->and($terminal?->effect_state)->toBe('not_started')
        ->and($terminal?->completed_at)->not->toBeNull()
        ->and($terminal?->row_version)->toBe(4)
        ->and($connection->table('integration_operation_results')->where('operation_id', $accepted->operationId->value)->doesntExist())->toBeTrue()
        ->and(fn () => $control->replacePending($samePayload))->toThrow(OperationControlConflict::class)
        ->and(fn () => $control->cancel(new CancelOperation(
            IntegrationScope::of('fixture_catalog', 'tenant:control'),
            $accepted->operationId,
            'duplicate_cancel',
        )))->toThrow(OperationControlConflict::class);
});

it('serializes the first replacement and HMAC alias backfill across two PostgreSQL processes', function (): void {
    if (! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')
        || ! function_exists('posix_kill')) {
        Assert::markTestSkipped('The concurrent PostgreSQL gate requires the pcntl and posix extensions.');
    }

    $configuration = operationControlPostgresConfiguration();
    config()->set('database.connections.integration_operations_control_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_control_test');
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
        'connection' => 'tenant:control',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 1,
        'owner_mode' => OwnerMode::ShadowRead->value,
        'cohort' => null,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_control_test');
    $connection = $database->connection('integration_operations_control_test');
    assertOperationControlTestDatabase($connection, $configuration['database']);

    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_control_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);

    app()->instance(DurableAcceptanceNotifier::class, new RecordingAcceptanceNotifier);
    /** @var OperationCoordinator $coordinator */
    $coordinator = app(OperationCoordinator::class);
    $accepted = $coordinator->accept(operationControlAcceptCommand(['record' => 1]));
    config()->set('integration-operations.encryption.active_version', 2);
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
        2 => 'base64:'.base64_encode(str_repeat('f', 32)),
    ]);
    config()->set('integration-operations.hmac.active_version', 2);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
        2 => 'base64:'.base64_encode(str_repeat('i', 32)),
    ]);
    app()->forgetInstance(BoundPayloadEnvelopeCodec::class);
    app()->forgetInstance(PayloadCipher::class);
    app()->forgetInstance(PayloadEncryptionKeyRing::class);
    app()->forgetInstance(LookupHmacKeyRing::class);
    app()->forgetInstance(HmacSha256::class);
    $manualResolver = new RejectingManualOperationResolver;
    $control = new DatabaseOperationControl(
        app(KernelDatabase::class),
        app(DefinitionRegistry::class),
        app(LookupHmacKeyRing::class),
        app(HmacSha256::class),
        app(CanonicalJsonV1::class),
        app(IntegrationContextCodec::class),
        app(BoundPayloadEnvelopeCodec::class),
        app(UlidFactory::class),
        app(OperationStateMachine::class),
        $manualResolver,
        app(Repository::class),
    );
    $replacement = new ReplacePendingOperation(
        IntegrationScope::of('fixture_catalog', 'tenant:control'),
        $accepted->operationId,
        1,
        new CanonicalObject(['record' => 2]),
    );
    $temporaryPrefix = sys_get_temp_dir().'/integration-operation-replace-'.bin2hex(random_bytes(8));
    $startFile = "{$temporaryPrefix}.start";
    $resultFiles = ["{$temporaryPrefix}.first", "{$temporaryPrefix}.second"];
    $remainingChildren = [];
    $database->disconnect('integration_operations_control_test');

    try {
        foreach ($resultFiles as $resultFile) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork the PostgreSQL replacement test process.');
            }

            if ($pid === 0) {
                try {
                    $deadline = microtime(true) + 10;

                    while (! is_file($startFile)) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Concurrent replacement start barrier timed out.');
                        }

                        usleep(1000);
                    }

                    $database->purge('integration_operations_control_test');

                    try {
                        $control->replacePending($replacement);
                        file_put_contents($resultFile, 'succeeded');
                    } catch (OperationControlConflict) {
                        file_put_contents($resultFile, 'conflict');
                    }

                    exit(0);
                } catch (Throwable $failure) {
                    file_put_contents($resultFile, 'ERROR:'.$failure::class);
                    exit(1);
                }
            }

            $remainingChildren[$pid] = true;
        }

        file_put_contents($startFile, 'go');
        $exitCodes = [];
        $deadline = microtime(true) + 15;

        while (true) {
            foreach (array_keys($remainingChildren) as $pid) {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);

                if ($waited === $pid) {
                    $exitCodes[$pid] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
                    unset($remainingChildren[$pid]);
                }
            }

            if ($remainingChildren === []) {
                break;
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Concurrent replacement workers exceeded the test timeout.');
            }

            usleep(1000);
        }

        $outcomes = array_map(static fn (string $path): string|false => file_get_contents($path), $resultFiles);
        sort($outcomes);

        expect(array_values($exitCodes))->toBe([0, 0])
            ->and($outcomes)->toBe(['conflict', 'succeeded']);
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

    $database->purge('integration_operations_control_test');
    $connection = $database->connection('integration_operations_control_test');

    expect($connection->table('integration_operations')->where('id', $accepted->operationId->value)->value('current_payload_revision'))->toBe(2)
        ->and($connection->table('integration_operation_payloads')->where('operation_id', $accepted->operationId->value)->count())->toBe(2)
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $accepted->operationId->value)->count())->toBe(2)
        ->and($connection->table('integration_operation_lookup_keys')->where('operation_id', $accepted->operationId->value)->where('key_version', 2)->count())->toBe(2);
});

/** @param array<string, mixed> $payload */
function operationControlAcceptCommand(
    array $payload,
    ?IntegrationContext $context = null,
    string $semanticSlot = 'replaceable',
): AcceptOperation {
    return new AcceptOperation(
        scope: IntegrationScope::of('fixture_catalog', 'tenant:control'),
        operationType: new OperationType('fixture_catalog.record.fetch'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity('catalog_record', $semanticSlot),
        payload: new CanonicalObject($payload),
        context: $context ?? IntegrationContext::make('correlation:initial'),
    );
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function operationControlPostgresConfiguration(): array
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

function assertOperationControlTestDatabase(Connection $connection, string $configuredDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass || ! is_string($current->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the operation control PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase($configuredDatabase, $current->database_name);
}

/** @return array<string, mixed> */
function decryptOperationControlContext(Connection $connection, OperationId $operationId, int $revision): array
{
    $payload = $connection->table('integration_operation_payloads')
        ->where('operation_id', $operationId->value)
        ->where('payload_revision', $revision)
        ->first();

    if (! $payload instanceof stdClass
        || ! is_int($payload->context_key_version ?? null)
        || ! is_string($payload->context_cipher ?? null)
        || ! is_string($payload->context_ciphertext ?? null)
        || ! is_string($payload->context_ciphertext_sha256 ?? null)
        || ! is_int($payload->context_schema_version ?? null)) {
        throw new RuntimeException('Persisted operation context is invalid.');
    }

    return app(BoundPayloadEnvelopeCodec::class)->decrypt(
        new EncryptedEnvelope(
            $payload->context_key_version,
            $payload->context_cipher,
            $payload->context_ciphertext,
            new Sha256Digest($payload->context_ciphertext_sha256),
        ),
        new PayloadEnvelopeBinding('context', $operationId, $revision, $payload->context_schema_version),
    )->values;
}
