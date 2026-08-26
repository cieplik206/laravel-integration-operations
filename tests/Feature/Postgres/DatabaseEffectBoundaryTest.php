<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\EffectBoundaryFailure;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Exceptions\EffectBoundaryViolation;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceCutoverBlocked;
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
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\Tests\Support\CallbackWriterFenceResolver;
use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Tests\Support\RecordingLeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Assert;

it('durably opens one single-effect boundary and advances only the in-memory lease handle after commit', function (): void {
    [$connection, $observer, $coordinator, $manager, $boundaryFactory] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-success'));
    $claim = $manager->claim($receipt->operationId, 'worker:boundary-success')
        ?? throw new LogicException('Missing effect-boundary success lease.');
    $handle = new LeaseClaimHandle($claim);
    $boundary = $boundaryFactory->make($handle);
    $boundary->open();
    $operation = $connection->table('integration_operations')->where('id', $receipt->operationId->value)->first();
    $attempt = $connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->first();

    expect($boundary->wasOpened())->toBeTrue()
        ->and($handle->claim()->rowVersion)->toBe($claim->rowVersion + 1)
        ->and($operation?->status)->toBe('processing')
        ->and($operation?->effect_state)->toBe('possibly_applied')
        ->and($operation?->request_started_at)->not->toBeNull()
        ->and($attempt?->request_started_at)->toBe($operation?->request_started_at)
        ->and($connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->where('lease_expires_at', '>=', $connection->raw("clock_timestamp() + INTERVAL '16 seconds'"))
            ->exists())->toBeTrue()
        ->and($observer->table('integration_operations')->where('id', $receipt->operationId->value)->value('effect_state'))->toBe('possibly_applied')
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $receipt->operationId->value)->orderByDesc('sequence')->value('reason_code'))->toBe('effect_boundary_opened');

    expectEffectBoundarySqlState($connection, fn (): int => $connection
        ->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update(['request_started_at' => null]), '55000');
    expectEffectBoundarySqlState($connection, fn (): int => $connection
        ->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->whereNull('finished_at')
        ->update(['request_started_at' => $connection->raw("request_started_at + INTERVAL '1 microsecond'")]), '55000');

    $fingerprint = effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value);

    try {
        $boundary->open();
        throw new LogicException('Expected a second boundary open to fail.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::AlreadyOpened);
    }

    $freshBoundary = $boundaryFactory->make($handle);

    try {
        $freshBoundary->open();
        throw new LogicException('Expected a fresh boundary wrapper to observe the durable marker.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::AlreadyOpened);
    }

    expect(effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value))->toBe($fingerprint);
});

it('refreshes a nearly exhausted claim lease to the full transport budget before returning permission', function (): void {
    [$connection, $observer, $coordinator, $manager, $boundaryFactory] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    config()->set('integration-operations.leases.seconds', 120);
    config()->set('integration-operations.leases.heartbeat_seconds', 30);
    config()->set('integration-operations.leases.connect_timeout_seconds', 10);
    config()->set('integration-operations.leases.request_timeout_seconds', 60);
    config()->set('integration-operations.leases.safety_margin_seconds', 15);
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-near-expiry-refresh'));
    $claim = $manager->claim($receipt->operationId, 'worker:boundary-near-expiry-refresh')
        ?? throw new LogicException('Missing near-expiry boundary lease.');
    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_expires_at' => $connection->raw("clock_timestamp() + INTERVAL '2 seconds'"),
        ]);
    $before = $observer->selectOne(
        <<<'SQL'
            SELECT EXTRACT(EPOCH FROM lease_expires_at - clock_timestamp()) AS remaining_seconds
            FROM integration_operations
            WHERE id = ?
            SQL,
        [$receipt->operationId->value],
    );

    if (! $before instanceof stdClass || ! is_string($before->remaining_seconds ?? null)) {
        throw new LogicException('Missing near-expiry lease observation.');
    }

    expect((float) $before->remaining_seconds)->toBeGreaterThan(0.0)
        ->toBeLessThanOrEqual(2.0);

    $handle = new LeaseClaimHandle($claim);
    $boundary = $boundaryFactory->make($handle);
    $boundary->open();
    $operation = $observer->selectOne(
        <<<'SQL'
            SELECT request_started_at,
                lease_heartbeat_at,
                lease_expires_at,
                EXTRACT(EPOCH FROM lease_expires_at - request_started_at) AS transport_budget_seconds,
                row_version,
                effect_state
            FROM integration_operations
            WHERE id = ?
            SQL,
        [$receipt->operationId->value],
    );
    $attempt = $observer->table('integration_operation_attempts')
        ->where('operation_id', $receipt->operationId->value)
        ->whereNull('finished_at')
        ->first();

    if (! $operation instanceof stdClass
        || ! is_string($operation->request_started_at ?? null)
        || ! is_string($operation->lease_heartbeat_at ?? null)
        || ! is_string($operation->lease_expires_at ?? null)
        || ! is_string($operation->transport_budget_seconds ?? null)
        || ! is_int($operation->row_version ?? null)
        || ! is_string($operation->effect_state ?? null)
        || ! $attempt instanceof stdClass
        || ! is_string($attempt->request_started_at ?? null)) {
        throw new LogicException('Missing committed near-expiry boundary evidence.');
    }

    expect($boundary->wasOpened())->toBeTrue()
        ->and($handle->claim()->rowVersion)->toBe($claim->rowVersion + 1)
        ->and($operation->row_version)->toBe($claim->rowVersion + 1)
        ->and($operation->effect_state)->toBe('possibly_applied')
        ->and($operation->request_started_at)->toBe($operation->lease_heartbeat_at)
        ->and($attempt->request_started_at)->toBe($operation->request_started_at)
        ->and((float) $operation->transport_budget_seconds)->toBeGreaterThanOrEqual(85.0)
        ->and($observer->table('integration_operation_transitions')
            ->where('operation_id', $receipt->operationId->value)
            ->orderByDesc('sequence')
            ->value('reason_code'))->toBe('effect_boundary_opened');
});

it('rejects stale capabilities, expired leases, unexpected results, and read-only boundaries without mutation', function (): void {
    [$connection, , $coordinator, $manager, $boundaryFactory] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );

    foreach (['token', 'digest_as_token', 'row_version', 'owner', 'scope', 'purpose'] as $variant) {
        $receipt = $coordinator->accept(effectBoundaryCommand('boundary-stale-'.$variant));
        $claim = $manager->claim($receipt->operationId, 'worker:boundary-stale-'.$variant)
            ?? throw new LogicException('Missing stale-capability lease.');
        $persistedDigest = $connection->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->value('lease_token_sha256');

        if (! is_string($persistedDigest)) {
            throw new LogicException('Missing persisted lease digest.');
        }

        $stale = match ($variant) {
            'token' => effectBoundaryClaim($claim, token: str_repeat('a', 64)),
            'digest_as_token' => effectBoundaryClaim($claim, token: $persistedDigest),
            'row_version' => effectBoundaryClaim($claim, rowVersion: $claim->rowVersion + 1),
            'owner' => effectBoundaryClaim($claim, owner: 'worker:foreign-owner'),
            'scope' => effectBoundaryClaim($claim, scope: IntegrationScope::of('fixture_dispatch', 'tenant:foreign')),
            'purpose' => effectBoundaryClaim($claim, purpose: LeasePurpose::Reconcile),
        };
        $before = effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value);

        try {
            $boundaryFactory->make(new LeaseClaimHandle($stale))->open();
            throw new LogicException('Expected a stale effect-boundary capability to fail.');
        } catch (EffectBoundaryViolation $failure) {
            expect($failure->failure)->toBe(EffectBoundaryFailure::LeaseLost);
        }

        expect(effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value))->toBe($before);
    }

    $expiredReceipt = $coordinator->accept(effectBoundaryCommand('boundary-expired'));
    $expiredClaim = $manager->claim($expiredReceipt->operationId, 'worker:boundary-expired')
        ?? throw new LogicException('Missing expired boundary lease.');
    $connection->table('integration_operations')->where('id', $expiredReceipt->operationId->value)->update([
        'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '3 minutes'"),
        'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '2 minutes'"),
        'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '1 minute'"),
    ]);
    $expiredBefore = effectBoundaryPersistenceFingerprint($connection, $expiredReceipt->operationId->value);

    try {
        $boundaryFactory->make(new LeaseClaimHandle($expiredClaim))->open();
        throw new LogicException('Expected an expired effect-boundary lease to fail.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::LeaseLost);
    }

    expect(effectBoundaryPersistenceFingerprint($connection, $expiredReceipt->operationId->value))->toBe($expiredBefore);

    $resultReceipt = $coordinator->accept(effectBoundaryCommand('boundary-result'));
    $resultClaim = $manager->claim($resultReceipt->operationId, 'worker:boundary-result')
        ?? throw new LogicException('Missing unexpected-result boundary lease.');
    insertEffectBoundaryGhostResult($connection, $resultReceipt->operationId->value);
    $resultBefore = effectBoundaryPersistenceFingerprint($connection, $resultReceipt->operationId->value);

    try {
        $boundaryFactory->make(new LeaseClaimHandle($resultClaim))->open();
        throw new LogicException('Expected an unexpected result to block the boundary.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::InvalidState);
    }

    expect(effectBoundaryPersistenceFingerprint($connection, $resultReceipt->operationId->value))->toBe($resultBefore);

    [$readConnection, , $readCoordinator, $readManager, $readBoundaryFactory] = prepareEffectBoundaryRuntime(
        'fixture_catalog',
        OwnerMode::ShadowRead,
    );
    $readReceipt = $readCoordinator->accept(effectBoundaryCommand('boundary-read-only', 'fixture_catalog'));
    $readClaim = $readManager->claim($readReceipt->operationId, 'worker:boundary-read-only')
        ?? throw new LogicException('Missing read-only boundary lease.');
    $readBefore = effectBoundaryPersistenceFingerprint($readConnection, $readReceipt->operationId->value);

    try {
        $readBoundaryFactory->make(new LeaseClaimHandle($readClaim))->open();
        throw new LogicException('Expected a read-only effect boundary to be forbidden.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::Forbidden);
    }

    expect(effectBoundaryPersistenceFingerprint($readConnection, $readReceipt->operationId->value))->toBe($readBefore);
});

it('rejects a managed mutation whose locked intent local type no longer matches the frozen definition', function (): void {
    [$connection, , $coordinator, $manager, $boundaryFactory] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-intent-local-type'));
    $intentId = $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->value('intent_id');

    if (! is_string($intentId)) {
        throw new LogicException('Missing effect-boundary intent identity.');
    }

    mutateEffectBoundaryIntentLocalType($connection, $intentId, 'foreign_resource');

    expect($manager->claim($receipt->operationId, 'worker:identity-before-claim'))->toBeNull()
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('pending')
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->count())->toBe(0);

    mutateEffectBoundaryIntentLocalType($connection, $intentId, 'fixture_resource');
    $claim = $manager->claim($receipt->operationId, 'worker:identity-before-boundary')
        ?? throw new LogicException('Missing effect-boundary identity lease.');
    mutateEffectBoundaryIntentLocalType($connection, $intentId, 'foreign_resource');
    $boundary = $boundaryFactory->make(new LeaseClaimHandle($claim));

    try {
        $boundary->open();
        throw new LogicException('Expected the mismatched intent identity to reject the effect boundary.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::InvalidState);
    }

    expect($boundary->wasOpened())->toBeFalse()
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('request_started_at'))->toBeNull()
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $receipt->operationId->value)->value('request_started_at'))->toBeNull();
});

it('requires the exact trusted cohort alias set while allowing readable N and N minus one aliases', function (): void {
    [$connection, , $coordinator, $manager, $boundaryFactory, $definitions] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::CanaryWrite,
        'cohort:boundary',
    );
    $ghostReceipt = $coordinator->accept(effectBoundaryCommand('boundary-ghost-alias'));
    $ghostClaim = $manager->claim($ghostReceipt->operationId, 'worker:boundary-ghost-alias')
        ?? throw new LogicException('Missing ghost-alias boundary lease.');
    $connection->table('integration_operation_writer_fence_aliases')->insert([
        'id' => app(UlidFactory::class)->generate()->value,
        'provider' => 'fixture_dispatch',
        'connection_key' => 'tenant:boundary',
        'operation_type' => 'fixture_dispatch.message.deliver',
        'generation' => 1,
        'key_version' => 999,
        'digest' => str_repeat('b', 64),
        'created_at' => $connection->raw('clock_timestamp()'),
    ]);

    try {
        $boundaryFactory->make(new LeaseClaimHandle($ghostClaim))->open();
        throw new LogicException('Expected a ghost writer-fence alias to be rejected.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::WriterFenceRejected);
    }

    $ghostOperation = $connection->table('integration_operations')->where('id', $ghostReceipt->operationId->value)->first();

    expect($ghostOperation?->status)->toBe('manual_review')
        ->and($ghostOperation?->effect_state)->toBe('not_started')
        ->and($ghostOperation?->request_started_at)->toBeNull()
        ->and($ghostOperation?->active_attempt_id)->toBeNull()
        ->and($connection->table('integration_operation_attempts')->where('operation_id', $ghostReceipt->operationId->value)->value('safe_outcome_category'))->toBe('writer_fence_rejected');

    $connection->table('integration_operation_writer_fence_aliases')
        ->where('provider', 'fixture_dispatch')
        ->where('connection_key', 'tenant:boundary')
        ->where('operation_type', 'fixture_dispatch.message.deliver')
        ->where('generation', 1)
        ->where('key_version', 999)
        ->update(['retired_at' => $connection->raw('clock_timestamp()')]);
    $rotationReceipt = $coordinator->accept(effectBoundaryCommand('boundary-n-and-n-minus-one'));
    $rotationClaim = $manager->claim($rotationReceipt->operationId, 'worker:boundary-n-and-n-minus-one')
        ?? throw new LogicException('Missing pre-rotation boundary lease.');
    $mismatchReceipt = $coordinator->accept(effectBoundaryCommand('boundary-same-key-mismatch'));
    $mismatchClaim = $manager->claim($mismatchReceipt->operationId, 'worker:boundary-same-key-mismatch')
        ?? throw new LogicException('Missing same-key mismatch boundary lease.');
    config()->set('integration-operations.hmac.active_version', 2);
    config()->set('integration-operations.hmac.keys', [
        1 => 'base64:'.base64_encode(str_repeat('h', 32)),
        2 => 'base64:'.base64_encode(str_repeat('i', 32)),
    ]);
    [$rotatedCoordinator, $rotatedManager, $rotatedBoundaryFactory] = buildEffectBoundaryRuntime($definitions);
    $rotationBefore = effectBoundaryPersistenceFingerprint($connection, $rotationReceipt->operationId->value);
    $connection->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION io_test_fail_writer_fence_alias_backfill() RETURNS trigger AS $$
        BEGIN
            IF NEW.key_version = 2 THEN
                RAISE EXCEPTION 'injected writer-fence alias backfill failure' USING ERRCODE = '23514';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
        SQL);
    $connection->unprepared(<<<'SQL'
        CREATE TRIGGER io_test_writer_fence_alias_backfill_failure
        BEFORE INSERT ON integration_operation_writer_fence_aliases
        FOR EACH ROW EXECUTE FUNCTION io_test_fail_writer_fence_alias_backfill()
        SQL);

    try {
        expect(fn () => $rotatedBoundaryFactory->make(new LeaseClaimHandle($rotationClaim))->open())
            ->toThrow(OperationPersistenceFailed::class);
    } finally {
        $connection->unprepared('DROP TRIGGER IF EXISTS io_test_writer_fence_alias_backfill_failure ON integration_operation_writer_fence_aliases');
        $connection->unprepared('DROP FUNCTION IF EXISTS io_test_fail_writer_fence_alias_backfill()');
    }

    expect(effectBoundaryPersistenceFingerprint($connection, $rotationReceipt->operationId->value))->toBe($rotationBefore)
        ->and($connection->table('integration_operation_writer_fence_aliases')
            ->where('provider', 'fixture_dispatch')
            ->where('connection_key', 'tenant:boundary')
            ->where('operation_type', 'fixture_dispatch.message.deliver')
            ->where('generation', 1)
            ->whereNull('retired_at')
            ->pluck('key_version')
            ->all())->toBe([1]);

    $rotatedBoundaryFactory->make(new LeaseClaimHandle($rotationClaim))->open();

    expect($connection->table('integration_operations')->where('id', $rotationReceipt->operationId->value)->value('effect_state'))->toBe('possibly_applied')
        ->and($connection->table('integration_operation_writer_fence_aliases')
            ->where('provider', 'fixture_dispatch')
            ->where('connection_key', 'tenant:boundary')
            ->where('operation_type', 'fixture_dispatch.message.deliver')
            ->where('generation', 1)
            ->whereNull('retired_at')
            ->orderBy('key_version')
            ->pluck('key_version')
            ->all())->toBe([1, 2]);

    $keyOneDigest = $connection->table('integration_operation_writer_fence_aliases')
        ->where('provider', 'fixture_dispatch')
        ->where('connection_key', 'tenant:boundary')
        ->where('operation_type', 'fixture_dispatch.message.deliver')
        ->where('generation', 1)
        ->where('key_version', 1)
        ->value('digest');

    if (! is_string($keyOneDigest)) {
        throw new LogicException('Missing key-one writer-fence alias digest.');
    }

    rewriteEffectBoundaryAliasDigest($connection, 1, str_repeat('c', 64));

    try {
        $rotatedBoundaryFactory->make(new LeaseClaimHandle($mismatchClaim))->open();
        throw new LogicException('Expected a same-key writer-fence digest mismatch to be rejected.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::WriterFenceRejected);
    } finally {
        rewriteEffectBoundaryAliasDigest($connection, 1, $keyOneDigest);
    }

    expect($connection->table('integration_operations')->where('id', $mismatchReceipt->operationId->value)->value('status'))->toBe('manual_review')
        ->and($connection->table('integration_operations')->where('id', $mismatchReceipt->operationId->value)->value('request_started_at'))->toBeNull();

    $retiredReceipt = $rotatedCoordinator->accept(effectBoundaryCommand('boundary-retired-active'));
    $retiredClaim = $rotatedManager->claim($retiredReceipt->operationId, 'worker:boundary-retired-active')
        ?? throw new LogicException('Missing retired-alias boundary lease.');
    $connection->table('integration_operation_writer_fence_aliases')
        ->where('provider', 'fixture_dispatch')
        ->where('connection_key', 'tenant:boundary')
        ->where('operation_type', 'fixture_dispatch.message.deliver')
        ->where('generation', 1)
        ->where('key_version', 2)
        ->update(['retired_at' => $connection->raw('clock_timestamp()')]);

    try {
        $rotatedBoundaryFactory->make(new LeaseClaimHandle($retiredClaim))->open();
        throw new LogicException('Expected a retired active cohort alias to be rejected.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::WriterFenceRejected);
    }

    expect($connection->table('integration_operations')->where('id', $retiredReceipt->operationId->value)->value('request_started_at'))->toBeNull()
        ->and($connection->table('integration_operation_writer_fence_aliases')
            ->where('provider', 'fixture_dispatch')
            ->where('connection_key', 'tenant:boundary')
            ->where('operation_type', 'fixture_dispatch.message.deliver')
            ->where('generation', 1)
            ->where('key_version', 2)
            ->whereNotNull('retired_at')
            ->exists())->toBeTrue();
});

it('atomically audits config divergence and rolls back a failure between operation and attempt markers', function (): void {
    [$connection, , $coordinator, $manager, $boundaryFactory] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $rejectedReceipt = $coordinator->accept(effectBoundaryCommand('boundary-config-diverged'));
    $rejectedClaim = $manager->claim($rejectedReceipt->operationId, 'worker:boundary-config-diverged')
        ?? throw new LogicException('Missing config-divergence boundary lease.');
    config()->set('integration-operations.writer_fences.0.owner_mode', OwnerMode::Off->value);

    try {
        $boundaryFactory->make(new LeaseClaimHandle($rejectedClaim))->open();
        throw new LogicException('Expected config divergence to reject the boundary.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::WriterFenceRejected);
    }

    $rejected = $connection->table('integration_operations')->where('id', $rejectedReceipt->operationId->value)->first();

    expect($rejected?->status)->toBe('manual_review')
        ->and($rejected?->effect_state)->toBe('not_started')
        ->and($rejected?->request_started_at)->toBeNull()
        ->and($rejected?->lease_token_sha256)->toBeNull()
        ->and($rejected?->active_attempt_id)->toBeNull()
        ->and($connection->table('integration_operation_transitions')->where('operation_id', $rejectedReceipt->operationId->value)->orderByDesc('sequence')->value('reason_code'))->toBe('effect_boundary_writer_fence_rejected');

    config()->set('integration-operations.writer_fences.0.owner_mode', OwnerMode::On->value);
    $faultReceipt = $coordinator->accept(effectBoundaryCommand('boundary-marker-fault'));
    $faultClaim = $manager->claim($faultReceipt->operationId, 'worker:boundary-marker-fault')
        ?? throw new LogicException('Missing marker-fault boundary lease.');
    $before = effectBoundaryPersistenceFingerprint($connection, $faultReceipt->operationId->value);
    $connection->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION io_test_fail_attempt_boundary_marker() RETURNS trigger AS $$
        BEGIN
            IF OLD.request_started_at IS NULL AND NEW.request_started_at IS NOT NULL THEN
                RAISE EXCEPTION 'injected boundary marker failure' USING ERRCODE = '23514';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
        SQL);
    $connection->unprepared(<<<'SQL'
        CREATE TRIGGER io_test_attempt_boundary_marker_failure
        BEFORE UPDATE ON integration_operation_attempts
        FOR EACH ROW EXECUTE FUNCTION io_test_fail_attempt_boundary_marker()
        SQL);

    try {
        expect(fn () => $boundaryFactory->make(new LeaseClaimHandle($faultClaim))->open())
            ->toThrow(OperationPersistenceFailed::class);
    } finally {
        $connection->unprepared('DROP TRIGGER IF EXISTS io_test_attempt_boundary_marker_failure ON integration_operation_attempts');
        $connection->unprepared('DROP FUNCTION IF EXISTS io_test_fail_attempt_boundary_marker()');
    }

    expect(effectBoundaryPersistenceFingerprint($connection, $faultReceipt->operationId->value))->toBe($before);
    $retryHandle = new LeaseClaimHandle($faultClaim);
    $boundaryFactory->make($retryHandle)->open();

    expect($connection->table('integration_operations')->where('id', $faultReceipt->operationId->value)->value('effect_state'))->toBe('possibly_applied')
        ->and($retryHandle->claim()->rowVersion)->toBe($faultClaim->rowVersion + 1);
});

it('blocks cutover for every nonterminal old-generation operation and advances only after terminal completion', function (): void {
    [$connection, , $coordinator, , , $definitions] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-cutover-pending'));
    $authority = app(DatabaseWriterFenceAuthority::class);
    $scope = IntegrationScope::of('fixture_dispatch', 'tenant:boundary');
    $operationType = new OperationType('fixture_dispatch.message.deliver');

    expect(fn () => $authority->cutover(
        $scope,
        $operationType,
        1,
        new WriterFence(2, OwnerMode::On),
    ))->toThrow(WriterFenceCutoverBlocked::class)
        ->and($connection->table('integration_operation_writer_fences')->value('generation'))->toBe(1)
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('pending');

    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'status' => 'cancelled',
            'disposition' => 'cancelled',
            'completed_at' => $connection->raw('clock_timestamp()'),
            'row_version' => 2,
            'updated_at' => $connection->raw('clock_timestamp()'),
        ]);
    $authority->cutover(
        $scope,
        $operationType,
        1,
        new WriterFence(2, OwnerMode::On),
    );

    config()->set('integration-operations.writer_fences.0.generation', 2);
    [$generationTwoCoordinator] = buildEffectBoundaryRuntime($definitions);
    $generationTwoReceipt = $generationTwoCoordinator->accept(effectBoundaryCommand('boundary-cutover-generation-two'));

    expect($connection->table('integration_operation_writer_fences')->value('generation'))->toBe(2)
        ->and($connection->table('integration_operations')->where('id', $generationTwoReceipt->operationId->value)->value('writer_generation'))->toBe(2);
});

it('keeps a legacy pending operation unchanged while authority is absent and claims it after explicit bootstrap', function (): void {
    [$connection, , $coordinator, $manager] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-bootstrap-legacy'));
    removeEffectBoundaryWriterFenceAuthority($connection);
    $before = effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value);

    expect($manager->claim($receipt->operationId, 'worker:bootstrap-before'))->toBeNull()
        ->and(effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value))->toBe($before);

    $authority = app(DatabaseWriterFenceAuthority::class);
    $scope = IntegrationScope::of('fixture_dispatch', 'tenant:boundary');
    $operationType = new OperationType('fixture_dispatch.message.deliver');
    $authority->bootstrap($scope, $operationType, new WriterFence(1, OwnerMode::On));
    $claim = $manager->claim($receipt->operationId, 'worker:bootstrap-after')
        ?? throw new LogicException('Explicit authority bootstrap did not release the legacy claim.');

    expect($claim->operationId->equals($receipt->operationId))->toBeTrue()
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('processing');

    removeEffectBoundaryWriterFenceAuthority($connection);

    expect(fn () => $authority->bootstrap($scope, $operationType, new WriterFence(1, OwnerMode::On)))
        ->toThrow(WriterFenceCutoverBlocked::class)
        ->and($connection->table('integration_operation_writer_fences')->count())->toBe(0)
        ->and($connection->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('processing');
});

it('cleans a hostile configured fence resolver before the durable boundary rejection decision', function (): void {
    [$connection, $observer, $coordinator, $manager, , $definitions] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-hostile-resolver'));
    $claim = $manager->claim($receipt->operationId, 'worker:boundary-hostile-resolver')
        ?? throw new LogicException('Missing hostile-resolver boundary lease.');
    $hostileFactory = new DatabaseEffectBoundaryFactory(
        app(KernelDatabase::class),
        $definitions,
        app(ContainerBindingInspector::class),
        app(DatabaseWriterFenceAuthority::class),
        new CallbackWriterFenceResolver(
            new WriterFence(1, OwnerMode::On),
            function () use ($connection): void {
                $connection->beginTransaction();
            },
        ),
        app(HmacSha256::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        app(LeaseTimingPolicy::class),
    );

    try {
        $hostileFactory->make(new LeaseClaimHandle($claim))->open();
        throw new LogicException('Expected hostile writer-fence resolver rejection.');
    } catch (EffectBoundaryViolation $failure) {
        expect($failure->failure)->toBe(EffectBoundaryFailure::WriterFenceRejected);
    }

    expect($connection->transactionLevel())->toBe(0)
        ->and($observer->table('integration_operations')->where('id', $receipt->operationId->value)->value('status'))->toBe('manual_review')
        ->and($observer->table('integration_operations')->where('id', $receipt->operationId->value)->value('request_started_at'))->toBeNull();
});

it('captures PostgreSQL decision time after an alias lock wait that crosses lease expiry', function (): void {
    requireEffectBoundaryProcessControl();
    [$connection, , $coordinator, $manager, , $definitions] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::CanaryWrite,
        'cohort:boundary-lock-wait',
    );
    config()->set('integration-operations.leases.seconds', 4);
    config()->set('integration-operations.leases.heartbeat_seconds', 1);
    config()->set('integration-operations.leases.connect_timeout_seconds', 1);
    config()->set('integration-operations.leases.request_timeout_seconds', 1);
    config()->set('integration-operations.leases.safety_margin_seconds', 1);
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-alias-lock-wait'));
    $claim = $manager->claim($receipt->operationId, 'worker:boundary-alias-lock-wait')
        ?? throw new LogicException('Missing alias-lock-wait boundary lease.');
    $before = effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value);
    $configuration = effectBoundaryPostgresConfiguration();
    config()->set('database.connections.integration_operations_boundary_locker_test', $configuration);
    /** @var DatabaseManager $database */
    $database = app('db');

    foreach (array_keys($database->getConnections()) as $connectionName) {
        $database->purge((string) $connectionName);
    }

    $directory = sys_get_temp_dir().'/integration-operations-boundary-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the boundary concurrency directory.');
    }

    $ready = $directory.'/ready';
    $go = $directory.'/go';
    $result = $directory.'/result';
    $applicationName = 'integration-operations-boundary-'.bin2hex(random_bytes(6));
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the boundary concurrency worker.');
    }

    if ($pid === 0) {
        try {
            /** @var DatabaseManager $childDatabase */
            $childDatabase = app('db');

            foreach (array_keys($childDatabase->getConnections()) as $connectionName) {
                $childDatabase->purge((string) $connectionName);
            }

            $childConnection = $childDatabase->connection('integration_operations_boundary_test');
            $childConnection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
            file_put_contents($ready, 'ready', LOCK_EX);
            waitForEffectBoundaryFile($go, 10);
            [, , $boundaryFactory] = buildEffectBoundaryRuntime($definitions);

            try {
                $boundaryFactory->make(new LeaseClaimHandle($claim))->open();
                $outcome = 'opened';
            } catch (EffectBoundaryViolation $failure) {
                $outcome = $failure->failure->value;
            }

            file_put_contents($result, $outcome, LOCK_EX);
            exit(0);
        } catch (Throwable) {
            file_put_contents($result, 'child_failure', LOCK_EX);
            exit(1);
        }
    }

    try {
        waitForEffectBoundaryFile($ready, 10);
        $locker = $database->connection('integration_operations_boundary_locker_test');
        $locker->beginTransaction();
        $locker->table('integration_operation_writer_fence_aliases')
            ->where('provider', 'fixture_dispatch')
            ->where('connection_key', 'tenant:boundary')
            ->where('operation_type', 'fixture_dispatch.message.deliver')
            ->where('generation', 1)
            ->where('key_version', 1)
            ->lockForUpdate()
            ->first();
        file_put_contents($go, 'go', LOCK_EX);
        waitForEffectBoundaryPostgresLock($locker, $applicationName, 10);
        $locker->selectOne('SELECT pg_sleep(5)');
        $locker->commit();
        waitForEffectBoundaryChild($pid, 10);
        waitForEffectBoundaryFile($result, 10);
        $outcome = file_get_contents($result);
        $observer = $database->connection('integration_operations_boundary_observer_test');

        expect($outcome)->toBe(EffectBoundaryFailure::LeaseLost->value)
            ->and(effectBoundaryPersistenceFingerprint($observer, $receipt->operationId->value))->toBe($before)
            ->and($observer->table('integration_operations')->where('id', $receipt->operationId->value)->value('request_started_at'))->toBeNull();
    } finally {
        if (isset($locker) && $locker->transactionLevel() > 0) {
            $locker->rollBack(0);
        }

        if (effectBoundaryChildIsRunning($pid)) {
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
});

it('serializes boundary and cutover through the authority-first lock in both orderings', function (string $ordering): void {
    $outcome = runEffectBoundaryCutoverRace($ordering);

    expect($outcome['boundary'])->toBe('opened')
        ->and($outcome['cutover'])->toBe('blocked')
        ->and($outcome['effect_state'])->toBe('possibly_applied')
        ->and($outcome['authority_generation'])->toBe(1);
})->with(['boundary_first', 'cutover_first']);

it('serializes authority bootstrap and claim through the shared bootstrap barrier in both orderings', function (string $ordering): void {
    $outcome = runEffectBoundaryBootstrapClaimRace($ordering);

    expect($outcome['bootstrap'])->toBe('bootstrapped')
        ->and($outcome['authority_count'])->toBe(1);

    if ($ordering === 'claim_first') {
        expect($outcome['claim'])->toBe('null')
            ->and($outcome['unchanged_before_followup'])->toBeTrue()
            ->and($outcome['followup_claim'])->toBe('claimed')
            ->and($outcome['status'])->toBe('processing');

        return;
    }

    expect($outcome['claim'])->toBe('claimed')
        ->and($outcome['followup_claim'])->toBe('not_needed')
        ->and($outcome['status'])->toBe('processing');
})->with(['claim_first', 'bootstrap_first']);

it('allows exactly one of two concurrent cutovers to advance the authority epoch', function (): void {
    $outcome = runConcurrentEffectBoundaryCutovers();

    expect($outcome['workers'])->toBe(['blocked', 'cutover'])
        ->and($outcome['generation'])->toBe(2)
        ->and($outcome['epoch'])->toBe(2);
});

it('makes a boundary capability stale after a concurrent recovery finalizes its active attempt', function (): void {
    $outcome = runEffectBoundaryStaleOwnerRecoveryRace();

    expect($outcome['recovery'])->toBe('recovered:1')
        ->and($outcome['boundary'])->toBe(EffectBoundaryFailure::LeaseLost->value)
        ->and($outcome['boundary_was_noop'])->toBeTrue()
        ->and($outcome['status'])->toBe('pending')
        ->and($outcome['request_started_at'])->toBeNull();
});

/**
 * @return array{Connection, Connection, OperationCoordinator, DatabaseOperationLeaseManager, DatabaseEffectBoundaryFactory, DefinitionRegistry}
 */
function prepareEffectBoundaryRuntime(
    string $provider,
    OwnerMode $ownerMode,
    ?string $cohort = null,
): array {
    $configuration = effectBoundaryPostgresConfiguration();
    config()->set('database.connections.integration_operations_boundary_test', $configuration);
    config()->set('database.connections.integration_operations_boundary_observer_test', $configuration);
    config()->set('integration-operations.database.connection', 'integration_operations_boundary_test');
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
    config()->set('integration-operations.writer_fences', [[
        'provider' => $provider,
        'connection' => 'tenant:boundary',
        'operation_type' => $provider === 'fixture_catalog'
            ? 'fixture_catalog.record.fetch'
            : 'fixture_dispatch.message.deliver',
        'generation' => 1,
        'owner_mode' => $ownerMode->value,
        'cohort' => $cohort,
    ]]);

    /** @var DatabaseManager $database */
    $database = app('db');
    $database->purge('integration_operations_boundary_test');
    $database->purge('integration_operations_boundary_observer_test');
    $connection = $database->connection('integration_operations_boundary_test');
    $observer = $database->connection('integration_operations_boundary_observer_test');
    assertEffectBoundaryTestDatabase($connection, $configuration['database']);
    $migrationExitCode = app(ConsoleKernel::class)->call('migrate:fresh', [
        '--database' => 'integration_operations_boundary_test',
        '--path' => dirname(__DIR__, 3).'/database/migrations',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($migrationExitCode)->toBe(0);
    $definitions = $provider === 'fixture_dispatch'
        ? new DefinitionRegistry
        : app(DefinitionRegistry::class);

    if ($provider === 'fixture_dispatch') {
        $definitions->register(FakeSingleEffectDefinitionProvider::class);
        $definitions->freeze(app(ContainerBindingInspector::class));
    }

    [$coordinator, $manager, $boundaryFactory] = buildEffectBoundaryRuntime($definitions);

    return [$connection, $observer, $coordinator, $manager, $boundaryFactory, $definitions];
}

/** @return array{DatabaseOperationCoordinator, DatabaseOperationLeaseManager, DatabaseEffectBoundaryFactory} */
function buildEffectBoundaryRuntime(DefinitionRegistry $definitions): array
{
    $notifier = new RecordingAcceptanceNotifier;
    app()->instance(DurableAcceptanceNotifier::class, $notifier);
    $incidents = new RecordingLeaseRecoveryIncidentNotifier;
    app()->instance(LeaseRecoveryIncidentNotifier::class, $incidents);
    app()->forgetInstance(LookupHmacKeyRing::class);
    app()->forgetInstance(HmacSha256::class);
    app()->forgetInstance(DatabaseWriterFenceAuthority::class);
    $authority = app(DatabaseWriterFenceAuthority::class);
    $coordinator = new DatabaseOperationCoordinator(
        app(KernelDatabase::class),
        $definitions,
        new ConfigLocalReferenceTypeRegistry(['fixture_resource']),
        app(WriterFenceResolver::class),
        $authority,
        app(LookupHmacKeyRing::class),
        app(HmacSha256::class),
        app(CanonicalJsonV1::class),
        app(BoundPayloadEnvelopeCodec::class),
        app(UlidFactory::class),
        app(OperationStateMachine::class),
        $notifier,
        app(Repository::class),
    );
    $manager = new DatabaseOperationLeaseManager(
        app(KernelDatabase::class),
        $definitions,
        app(ContainerBindingInspector::class),
        $authority,
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        $incidents,
        app(UlidFactory::class),
        app(LeaseTimingPolicy::class),
        app(Repository::class),
    );
    $boundaryFactory = new DatabaseEffectBoundaryFactory(
        app(KernelDatabase::class),
        $definitions,
        app(ContainerBindingInspector::class),
        $authority,
        app(WriterFenceResolver::class),
        app(HmacSha256::class),
        app(OperationStateMachine::class),
        app(DatabaseTransitionRecorder::class),
        app(LeaseTimingPolicy::class),
    );

    return [$coordinator, $manager, $boundaryFactory];
}

function effectBoundaryCommand(string $slot, string $provider = 'fixture_dispatch'): AcceptOperation
{
    return new AcceptOperation(
        scope: IntegrationScope::of($provider, 'tenant:boundary'),
        operationType: new OperationType($provider === 'fixture_catalog'
            ? 'fixture_catalog.record.fetch'
            : 'fixture_dispatch.message.deliver'),
        versions: new OperationDefinitionVersions(1, 1, 1),
        intent: new IntentIdentity(
            'fixture_resource',
            $provider === 'fixture_dispatch' ? 'default' : $slot,
            $provider === 'fixture_dispatch'
                ? new LocalReference('fixture_resource', 'resource:'.$slot)
                : null,
        ),
        payload: new CanonicalObject(['value' => $slot]),
        context: IntegrationContext::make('correlation:'.$slot),
    );
}

function effectBoundaryClaim(
    LeaseClaim $claim,
    ?IntegrationScope $scope = null,
    ?LeasePurpose $purpose = null,
    ?string $owner = null,
    ?string $token = null,
    ?int $rowVersion = null,
): LeaseClaim {
    return new LeaseClaim(
        $claim->operationId,
        $scope ?? $claim->scope,
        $purpose ?? $claim->purpose,
        $owner ?? $claim->owner,
        $token ?? $claim->token(),
        $rowVersion ?? $claim->rowVersion,
    );
}

function mutateEffectBoundaryIntentLocalType(Connection $connection, string $intentId, string $localType): void
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

function effectBoundaryPersistenceFingerprint(Connection $connection, string $operationId): string
{
    return hash('sha256', json_encode([
        'operation' => $connection->table('integration_operations')->where('id', $operationId)->first(),
        'attempts' => $connection->table('integration_operation_attempts')->where('operation_id', $operationId)->orderBy('attempt_no')->get()->all(),
        'transitions' => $connection->table('integration_operation_transitions')->where('operation_id', $operationId)->orderBy('sequence')->get()->all(),
        'results' => $connection->table('integration_operation_results')->where('operation_id', $operationId)->get()->all(),
    ], JSON_THROW_ON_ERROR));
}

function insertEffectBoundaryGhostResult(Connection $connection, string $operationId): void
{
    $ciphertext = 'ghost-boundary-result';
    $connection->table('integration_operation_results')->insert([
        'operation_id' => $operationId,
        'result_type' => 'fixture.operation_result',
        'result_schema_version' => 1,
        'result_key_version' => 1,
        'result_cipher' => 'AES-256-GCM',
        'result_ciphertext' => $ciphertext,
        'result_ciphertext_sha256' => hash('sha256', $ciphertext),
        'created_at' => $connection->raw('clock_timestamp()'),
    ]);
}

function removeEffectBoundaryWriterFenceAuthority(Connection $connection): void
{
    $connection->statement('ALTER TABLE integration_operation_writer_fence_aliases DISABLE TRIGGER USER');
    $connection->statement('ALTER TABLE integration_operation_writer_fences DISABLE TRIGGER USER');

    try {
        $connection->table('integration_operation_writer_fence_aliases')->delete();
        $connection->table('integration_operation_writer_fences')->delete();
    } finally {
        $connection->statement('ALTER TABLE integration_operation_writer_fences ENABLE TRIGGER USER');
        $connection->statement('ALTER TABLE integration_operation_writer_fence_aliases ENABLE TRIGGER USER');
    }
}

function rewriteEffectBoundaryAliasDigest(Connection $connection, int $keyVersion, string $digest): void
{
    $connection->statement('ALTER TABLE integration_operation_writer_fence_aliases DISABLE TRIGGER io_writer_fence_aliases_immutable');

    try {
        $connection->table('integration_operation_writer_fence_aliases')
            ->where('provider', 'fixture_dispatch')
            ->where('connection_key', 'tenant:boundary')
            ->where('operation_type', 'fixture_dispatch.message.deliver')
            ->where('generation', 1)
            ->where('key_version', $keyVersion)
            ->update(['digest' => $digest]);
    } finally {
        $connection->statement('ALTER TABLE integration_operation_writer_fence_aliases ENABLE TRIGGER io_writer_fence_aliases_immutable');
    }
}

/**
 * @return array{driver: 'pgsql', host: string, port: int, database: string, username: string, password: string, charset: 'utf8', prefix: '', schema: 'public', sslmode: string}
 */
function effectBoundaryPostgresConfiguration(): array
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

function assertEffectBoundaryTestDatabase(Connection $connection, string $configuredDatabase): void
{
    $current = $connection->selectOne('SELECT current_database() AS database_name');

    if (! $current instanceof stdClass || ! is_string($current->database_name ?? null)) {
        throw new RuntimeException('Unable to verify the effect-boundary PostgreSQL test database.');
    }

    PostgresTestDatabaseGuard::assertConnectedDatabase($configuredDatabase, $current->database_name);
}

/** @param Closure(): mixed $mutation */
function expectEffectBoundarySqlState(Connection $connection, Closure $mutation, string $sqlState): void
{
    try {
        $connection->transaction(fn (): mixed => $mutation(), attempts: 1);
        Assert::fail("Expected PostgreSQL SQLSTATE {$sqlState}.");
    } catch (Throwable $failure) {
        expect((string) $failure->getCode())->toBe($sqlState);
    }
}

function requireEffectBoundaryProcessControl(): void
{
    foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill'] as $function) {
        if (! function_exists($function)) {
            throw new RuntimeException("The real PostgreSQL concurrency gate requires {$function}().");
        }
    }
}

function waitForEffectBoundaryFile(string $path, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the boundary concurrency barrier.');
        }

        usleep(20_000);
    }
}

function waitForEffectBoundaryPostgresLock(
    Connection $connection,
    string $applicationName,
    int $timeoutSeconds,
): void {
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        $waiting = $connection->table('pg_stat_activity')
            ->where('application_name', $applicationName)
            ->where('wait_event_type', 'Lock')
            ->exists();

        if ($waiting) {
            return;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Boundary worker never entered a PostgreSQL lock wait.');
        }

        usleep(20_000);
    }
}

function waitForEffectBoundaryChild(int $pid, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        $waited = pcntl_waitpid($pid, $status, WNOHANG);

        if ($waited === $pid) {
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException('Boundary concurrency worker failed.');
            }

            return;
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the boundary concurrency worker.');
        }

        usleep(20_000);
    }
}

function effectBoundaryChildIsRunning(int $pid): bool
{
    return pcntl_waitpid($pid, $status, WNOHANG) === 0;
}

/**
 * @return array{boundary: string, cutover: string, effect_state: string, authority_generation: int}
 */
function runEffectBoundaryCutoverRace(string $ordering): array
{
    requireEffectBoundaryProcessControl();

    if (! in_array($ordering, ['boundary_first', 'cutover_first'], true)) {
        throw new InvalidArgumentException('Unsupported boundary/cutover race ordering.');
    }

    [, , $coordinator, $manager, , $definitions] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::CanaryWrite,
        'cohort:boundary-race',
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-cutover-race-'.$ordering));
    $claim = $manager->claim($receipt->operationId, 'worker:boundary-cutover-race-'.$ordering)
        ?? throw new LogicException('Missing boundary/cutover race lease.');
    $configuration = effectBoundaryPostgresConfiguration();
    config()->set('database.connections.integration_operations_boundary_locker_test', $configuration);
    /** @var DatabaseManager $database */
    $database = app('db');

    foreach (array_keys($database->getConnections()) as $connectionName) {
        $database->purge((string) $connectionName);
    }

    $directory = sys_get_temp_dir().'/integration-operations-cutover-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the cutover concurrency directory.');
    }

    $boundaryReady = $directory.'/boundary-ready';
    $boundaryGo = $directory.'/boundary-go';
    $boundaryResult = $directory.'/boundary-result';
    $cutoverReady = $directory.'/cutover-ready';
    $cutoverGo = $directory.'/cutover-go';
    $cutoverResult = $directory.'/cutover-result';
    $boundaryApplication = 'integration-operations-boundary-race-'.bin2hex(random_bytes(5));
    $cutoverApplication = 'integration-operations-cutover-race-'.bin2hex(random_bytes(5));
    $boundaryPid = forkEffectBoundaryWorker(
        $definitions,
        $claim,
        $boundaryApplication,
        $boundaryReady,
        $boundaryGo,
        $boundaryResult,
    );
    $cutoverPid = forkEffectBoundaryCutoverWorker(
        new WriterFence(2, OwnerMode::CanaryWrite, 'cohort:boundary-race'),
        $cutoverApplication,
        $cutoverReady,
        $cutoverGo,
        $cutoverResult,
    );

    try {
        waitForEffectBoundaryFile($boundaryReady, 10);
        waitForEffectBoundaryFile($cutoverReady, 10);
        $locker = $database->connection('integration_operations_boundary_locker_test');
        $locker->beginTransaction();

        if ($ordering === 'boundary_first') {
            $locker->table('integration_operation_writer_fence_aliases')
                ->where('provider', 'fixture_dispatch')
                ->where('connection_key', 'tenant:boundary')
                ->where('operation_type', 'fixture_dispatch.message.deliver')
                ->where('generation', 1)
                ->lockForUpdate()
                ->first();
            file_put_contents($boundaryGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $boundaryApplication, 10);
            file_put_contents($cutoverGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $cutoverApplication, 10);
        } else {
            $locker->table('integration_operations')
                ->where('id', $receipt->operationId->value)
                ->lockForUpdate()
                ->first();
            file_put_contents($cutoverGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $cutoverApplication, 10);
            file_put_contents($boundaryGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $boundaryApplication, 10);
        }

        $locker->commit();
        waitForEffectBoundaryChild($boundaryPid, 10);
        waitForEffectBoundaryChild($cutoverPid, 10);
        waitForEffectBoundaryFile($boundaryResult, 10);
        waitForEffectBoundaryFile($cutoverResult, 10);
        $observer = $database->connection('integration_operations_boundary_observer_test');
        $effectState = $observer->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->value('effect_state');
        $authorityGeneration = $observer->table('integration_operation_writer_fences')->value('generation');

        if (! is_string($effectState) || ! is_int($authorityGeneration)) {
            throw new RuntimeException('Boundary/cutover race state could not be read.');
        }

        return [
            'boundary' => (string) file_get_contents($boundaryResult),
            'cutover' => (string) file_get_contents($cutoverResult),
            'effect_state' => $effectState,
            'authority_generation' => $authorityGeneration,
        ];
    } finally {
        if (isset($locker) && $locker->transactionLevel() > 0) {
            $locker->rollBack(0);
        }

        foreach ([$boundaryPid, $cutoverPid] as $pid) {
            if (effectBoundaryChildIsRunning($pid)) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }

        foreach ([$boundaryReady, $boundaryGo, $boundaryResult, $cutoverReady, $cutoverGo, $cutoverResult] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

/**
 * @return array{bootstrap: string, claim: string, authority_count: int, unchanged_before_followup: bool, followup_claim: string, status: string}
 */
function runEffectBoundaryBootstrapClaimRace(string $ordering): array
{
    requireEffectBoundaryProcessControl();

    if (! in_array($ordering, ['claim_first', 'bootstrap_first'], true)) {
        throw new InvalidArgumentException('Unsupported bootstrap/claim race ordering.');
    }

    [$connection, , $coordinator, , , $definitions] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-bootstrap-claim-'.$ordering));
    removeEffectBoundaryWriterFenceAuthority($connection);
    $before = effectBoundaryPersistenceFingerprint($connection, $receipt->operationId->value);
    $configuration = effectBoundaryPostgresConfiguration();
    config()->set('database.connections.integration_operations_boundary_locker_test', $configuration);
    /** @var DatabaseManager $database */
    $database = app('db');

    foreach (array_keys($database->getConnections()) as $connectionName) {
        $database->purge((string) $connectionName);
    }

    $directory = newEffectBoundaryRaceDirectory('bootstrap-claim');
    $claimReady = $directory.'/claim-ready';
    $claimGo = $directory.'/claim-go';
    $claimResult = $directory.'/claim-result';
    $bootstrapReady = $directory.'/bootstrap-ready';
    $bootstrapGo = $directory.'/bootstrap-go';
    $bootstrapResult = $directory.'/bootstrap-result';
    $claimApplication = 'integration-operations-claim-race-'.bin2hex(random_bytes(5));
    $bootstrapApplication = 'integration-operations-bootstrap-race-'.bin2hex(random_bytes(5));
    $claimPid = forkEffectBoundaryClaimWorker(
        $definitions,
        $receipt->operationId,
        $claimApplication,
        $claimReady,
        $claimGo,
        $claimResult,
    );
    $bootstrapPid = forkEffectBoundaryBootstrapWorker(
        $bootstrapApplication,
        $bootstrapReady,
        $bootstrapGo,
        $bootstrapResult,
    );

    try {
        waitForEffectBoundaryFile($claimReady, 10);
        waitForEffectBoundaryFile($bootstrapReady, 10);
        $locker = $database->connection('integration_operations_boundary_locker_test');
        $locker->beginTransaction();
        $locker->selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['integration-operation-writer-fence|fixture_dispatch|tenant:boundary|fixture_dispatch.message.deliver'],
        );

        if ($ordering === 'claim_first') {
            file_put_contents($claimGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $claimApplication, 10);
            file_put_contents($bootstrapGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $bootstrapApplication, 10);
        } else {
            file_put_contents($bootstrapGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $bootstrapApplication, 10);
            file_put_contents($claimGo, 'go', LOCK_EX);
            waitForEffectBoundaryPostgresLock($locker, $claimApplication, 10);
        }

        $locker->commit();
        waitForEffectBoundaryChild($claimPid, 10);
        waitForEffectBoundaryChild($bootstrapPid, 10);
        waitForEffectBoundaryFile($claimResult, 10);
        waitForEffectBoundaryFile($bootstrapResult, 10);
        $observer = $database->connection('integration_operations_boundary_observer_test');
        $unchanged = effectBoundaryPersistenceFingerprint($observer, $receipt->operationId->value) === $before;
        $claimOutcome = (string) file_get_contents($claimResult);
        $followup = 'not_needed';

        if ($ordering === 'claim_first') {
            [, $manager] = buildEffectBoundaryRuntime($definitions);
            $followup = $manager->claim($receipt->operationId, 'worker:bootstrap-claim-followup') === null
                ? 'null'
                : 'claimed';
        }

        $status = $observer->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->value('status');
        $authorityCount = $observer->table('integration_operation_writer_fences')->count();

        if (! is_string($status)) {
            throw new RuntimeException('Bootstrap/claim race state could not be read.');
        }

        return [
            'bootstrap' => (string) file_get_contents($bootstrapResult),
            'claim' => $claimOutcome,
            'authority_count' => $authorityCount,
            'unchanged_before_followup' => $unchanged,
            'followup_claim' => $followup,
            'status' => $status,
        ];
    } finally {
        if (isset($locker) && $locker->transactionLevel() > 0) {
            $locker->rollBack(0);
        }

        cleanupEffectBoundaryRace(
            [$claimPid, $bootstrapPid],
            [$claimReady, $claimGo, $claimResult, $bootstrapReady, $bootstrapGo, $bootstrapResult],
            $directory,
        );
    }
}

/** @return array{workers: list<string>, generation: int, epoch: int} */
function runConcurrentEffectBoundaryCutovers(): array
{
    requireEffectBoundaryProcessControl();
    prepareEffectBoundaryRuntime('fixture_dispatch', OwnerMode::On);
    $scope = IntegrationScope::of('fixture_dispatch', 'tenant:boundary');
    $operationType = new OperationType('fixture_dispatch.message.deliver');
    app(DatabaseWriterFenceAuthority::class)->bootstrap(
        $scope,
        $operationType,
        new WriterFence(1, OwnerMode::On),
    );
    $configuration = effectBoundaryPostgresConfiguration();
    config()->set('database.connections.integration_operations_boundary_locker_test', $configuration);
    /** @var DatabaseManager $database */
    $database = app('db');

    foreach (array_keys($database->getConnections()) as $connectionName) {
        $database->purge((string) $connectionName);
    }

    $directory = newEffectBoundaryRaceDirectory('dual-cutover');
    $firstReady = $directory.'/first-ready';
    $firstGo = $directory.'/first-go';
    $firstResult = $directory.'/first-result';
    $secondReady = $directory.'/second-ready';
    $secondGo = $directory.'/second-go';
    $secondResult = $directory.'/second-result';
    $firstApplication = 'integration-operations-dual-cutover-a-'.bin2hex(random_bytes(5));
    $secondApplication = 'integration-operations-dual-cutover-b-'.bin2hex(random_bytes(5));
    $next = new WriterFence(2, OwnerMode::On);
    $firstPid = forkEffectBoundaryCutoverWorker(
        $next,
        $firstApplication,
        $firstReady,
        $firstGo,
        $firstResult,
    );
    $secondPid = forkEffectBoundaryCutoverWorker(
        $next,
        $secondApplication,
        $secondReady,
        $secondGo,
        $secondResult,
    );

    try {
        waitForEffectBoundaryFile($firstReady, 10);
        waitForEffectBoundaryFile($secondReady, 10);
        $locker = $database->connection('integration_operations_boundary_locker_test');
        $locker->beginTransaction();
        $locker->table('integration_operation_writer_fences')
            ->where('provider', $scope->provider->value)
            ->where('connection_key', $scope->connection->value)
            ->where('operation_type', $operationType->value)
            ->lockForUpdate()
            ->first();
        file_put_contents($firstGo, 'go', LOCK_EX);
        waitForEffectBoundaryPostgresLock($locker, $firstApplication, 10);
        file_put_contents($secondGo, 'go', LOCK_EX);
        waitForEffectBoundaryPostgresLock($locker, $secondApplication, 10);
        $locker->commit();
        waitForEffectBoundaryChild($firstPid, 10);
        waitForEffectBoundaryChild($secondPid, 10);
        waitForEffectBoundaryFile($firstResult, 10);
        waitForEffectBoundaryFile($secondResult, 10);
        $workers = [
            (string) file_get_contents($firstResult),
            (string) file_get_contents($secondResult),
        ];
        sort($workers);
        $observer = $database->connection('integration_operations_boundary_observer_test');
        $authority = $observer->table('integration_operation_writer_fences')->first();

        if (! $authority instanceof stdClass
            || ! is_int($authority->generation ?? null)
            || ! is_int($authority->epoch ?? null)) {
            throw new RuntimeException('Concurrent cutover authority could not be read.');
        }

        return [
            'workers' => $workers,
            'generation' => $authority->generation,
            'epoch' => $authority->epoch,
        ];
    } finally {
        if (isset($locker) && $locker->transactionLevel() > 0) {
            $locker->rollBack(0);
        }

        cleanupEffectBoundaryRace(
            [$firstPid, $secondPid],
            [$firstReady, $firstGo, $firstResult, $secondReady, $secondGo, $secondResult],
            $directory,
        );
    }
}

/**
 * @return array{recovery: string, boundary: string, boundary_was_noop: bool, status: string, request_started_at: mixed}
 */
function runEffectBoundaryStaleOwnerRecoveryRace(): array
{
    requireEffectBoundaryProcessControl();
    [$connection, , $coordinator, $manager, , $definitions] = prepareEffectBoundaryRuntime(
        'fixture_dispatch',
        OwnerMode::On,
    );
    $receipt = $coordinator->accept(effectBoundaryCommand('boundary-stale-after-recovery'));
    $claim = $manager->claim($receipt->operationId, 'worker:stale-after-recovery')
        ?? throw new LogicException('Missing stale-owner recovery lease.');
    $connection->table('integration_operations')
        ->where('id', $receipt->operationId->value)
        ->update([
            'lease_acquired_at' => $connection->raw("clock_timestamp() - INTERVAL '3 minutes'"),
            'lease_heartbeat_at' => $connection->raw("clock_timestamp() - INTERVAL '2 minutes'"),
            'lease_expires_at' => $connection->raw("clock_timestamp() - INTERVAL '1 minute'"),
        ]);
    $configuration = effectBoundaryPostgresConfiguration();
    config()->set('database.connections.integration_operations_boundary_locker_test', $configuration);
    /** @var DatabaseManager $database */
    $database = app('db');

    foreach (array_keys($database->getConnections()) as $connectionName) {
        $database->purge((string) $connectionName);
    }

    $directory = newEffectBoundaryRaceDirectory('stale-owner');
    $recoveryReady = $directory.'/recovery-ready';
    $recoveryGo = $directory.'/recovery-go';
    $recoveryResult = $directory.'/recovery-result';
    $boundaryReady = $directory.'/boundary-ready';
    $boundaryGo = $directory.'/boundary-go';
    $boundaryResult = $directory.'/boundary-result';
    $recoveryApplication = 'integration-operations-stale-recovery-'.bin2hex(random_bytes(5));
    $boundaryApplication = 'integration-operations-stale-boundary-'.bin2hex(random_bytes(5));
    $recoveryPid = forkEffectBoundaryRecoveryWorker(
        $definitions,
        $recoveryApplication,
        $recoveryReady,
        $recoveryGo,
        $recoveryResult,
    );
    $boundaryPid = forkEffectBoundaryWorker(
        $definitions,
        $claim,
        $boundaryApplication,
        $boundaryReady,
        $boundaryGo,
        $boundaryResult,
    );

    try {
        waitForEffectBoundaryFile($recoveryReady, 10);
        waitForEffectBoundaryFile($boundaryReady, 10);
        $locker = $database->connection('integration_operations_boundary_locker_test');
        $locker->beginTransaction();
        $locker->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->lockForUpdate()
            ->first();
        file_put_contents($recoveryGo, 'go', LOCK_EX);
        waitForEffectBoundaryPostgresLock($locker, $recoveryApplication, 10);
        file_put_contents($boundaryGo, 'go', LOCK_EX);
        waitForEffectBoundaryPostgresLock($locker, $boundaryApplication, 10);
        $locker->commit();
        waitForEffectBoundaryChild($recoveryPid, 10);
        waitForEffectBoundaryChild($boundaryPid, 10);
        waitForEffectBoundaryFile($recoveryResult, 10);
        waitForEffectBoundaryFile($boundaryResult, 10);
        $observer = $database->connection('integration_operations_boundary_observer_test');
        $operation = $observer->table('integration_operations')
            ->where('id', $receipt->operationId->value)
            ->first();
        $attempts = $observer->table('integration_operation_attempts')
            ->where('operation_id', $receipt->operationId->value)
            ->orderBy('attempt_no')
            ->get();
        $transitions = $observer->table('integration_operation_transitions')
            ->where('operation_id', $receipt->operationId->value)
            ->orderBy('sequence')
            ->get();

        if (! $operation instanceof stdClass || ! is_string($operation->status ?? null)) {
            throw new RuntimeException('Stale-owner recovery state could not be read.');
        }

        return [
            'recovery' => (string) file_get_contents($recoveryResult),
            'boundary' => (string) file_get_contents($boundaryResult),
            'boundary_was_noop' => ($operation->row_version ?? null) === $claim->rowVersion + 1
                && $attempts->count() === 2
                && $attempts->whereNull('finished_at')->isEmpty()
                && $transitions->count() === 3
                && $transitions->last()?->reason_code === 'expired_execution_lease_before_boundary'
                && $observer->table('integration_operation_results')
                    ->where('operation_id', $receipt->operationId->value)
                    ->doesntExist(),
            'status' => $operation->status,
            'request_started_at' => $operation->request_started_at ?? null,
        ];
    } finally {
        if (isset($locker) && $locker->transactionLevel() > 0) {
            $locker->rollBack(0);
        }

        cleanupEffectBoundaryRace(
            [$recoveryPid, $boundaryPid],
            [$recoveryReady, $recoveryGo, $recoveryResult, $boundaryReady, $boundaryGo, $boundaryResult],
            $directory,
        );
    }
}

function newEffectBoundaryRaceDirectory(string $prefix): string
{
    $directory = sys_get_temp_dir().'/integration-operations-'.$prefix.'-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the boundary concurrency directory.');
    }

    return $directory;
}

/**
 * @param  list<int>  $pids
 * @param  list<string>  $paths
 */
function cleanupEffectBoundaryRace(array $pids, array $paths, string $directory): void
{
    foreach ($pids as $pid) {
        if (effectBoundaryChildIsRunning($pid)) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
}

function forkEffectBoundaryClaimWorker(
    DefinitionRegistry $definitions,
    OperationId $operationId,
    string $applicationName,
    string $ready,
    string $go,
    string $result,
): int {
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the claim race worker.');
    }

    if ($pid !== 0) {
        return $pid;
    }

    try {
        /** @var DatabaseManager $database */
        $database = app('db');

        foreach (array_keys($database->getConnections()) as $connectionName) {
            $database->purge((string) $connectionName);
        }

        $connection = $database->connection('integration_operations_boundary_test');
        $connection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        file_put_contents($ready, 'ready', LOCK_EX);
        waitForEffectBoundaryFile($go, 10);
        [, $manager] = buildEffectBoundaryRuntime($definitions);
        $claim = $manager->claim($operationId, 'worker:bootstrap-claim-race');
        file_put_contents($result, $claim === null ? 'null' : 'claimed', LOCK_EX);
        exit(0);
    } catch (Throwable) {
        file_put_contents($result, 'child_failure', LOCK_EX);
        exit(1);
    }
}

function forkEffectBoundaryBootstrapWorker(
    string $applicationName,
    string $ready,
    string $go,
    string $result,
): int {
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the bootstrap race worker.');
    }

    if ($pid !== 0) {
        return $pid;
    }

    try {
        /** @var DatabaseManager $database */
        $database = app('db');

        foreach (array_keys($database->getConnections()) as $connectionName) {
            $database->purge((string) $connectionName);
        }

        $connection = $database->connection('integration_operations_boundary_test');
        $connection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        file_put_contents($ready, 'ready', LOCK_EX);
        waitForEffectBoundaryFile($go, 10);
        app()->forgetInstance(DatabaseWriterFenceAuthority::class);
        app(DatabaseWriterFenceAuthority::class)->bootstrap(
            IntegrationScope::of('fixture_dispatch', 'tenant:boundary'),
            new OperationType('fixture_dispatch.message.deliver'),
            new WriterFence(1, OwnerMode::On),
        );
        file_put_contents($result, 'bootstrapped', LOCK_EX);
        exit(0);
    } catch (WriterFenceCutoverBlocked) {
        file_put_contents($result, 'blocked', LOCK_EX);
        exit(0);
    } catch (Throwable) {
        file_put_contents($result, 'child_failure', LOCK_EX);
        exit(1);
    }
}

function forkEffectBoundaryRecoveryWorker(
    DefinitionRegistry $definitions,
    string $applicationName,
    string $ready,
    string $go,
    string $result,
): int {
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the recovery race worker.');
    }

    if ($pid !== 0) {
        return $pid;
    }

    try {
        /** @var DatabaseManager $database */
        $database = app('db');

        foreach (array_keys($database->getConnections()) as $connectionName) {
            $database->purge((string) $connectionName);
        }

        $connection = $database->connection('integration_operations_boundary_test');
        $connection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        file_put_contents($ready, 'ready', LOCK_EX);
        waitForEffectBoundaryFile($go, 10);
        [, $manager] = buildEffectBoundaryRuntime($definitions);
        $batch = $manager->recoverExpired(IntegrationScope::of('fixture_dispatch', 'tenant:boundary'));
        file_put_contents($result, 'recovered:'.$batch->recovered, LOCK_EX);
        exit(0);
    } catch (Throwable) {
        file_put_contents($result, 'child_failure', LOCK_EX);
        exit(1);
    }
}

function forkEffectBoundaryWorker(
    DefinitionRegistry $definitions,
    LeaseClaim $claim,
    string $applicationName,
    string $ready,
    string $go,
    string $result,
): int {
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the boundary race worker.');
    }

    if ($pid !== 0) {
        return $pid;
    }

    try {
        /** @var DatabaseManager $database */
        $database = app('db');

        foreach (array_keys($database->getConnections()) as $connectionName) {
            $database->purge((string) $connectionName);
        }

        $connection = $database->connection('integration_operations_boundary_test');
        $connection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        file_put_contents($ready, 'ready', LOCK_EX);
        waitForEffectBoundaryFile($go, 10);
        [, , $factory] = buildEffectBoundaryRuntime($definitions);

        try {
            $factory->make(new LeaseClaimHandle($claim))->open();
            $outcome = 'opened';
        } catch (EffectBoundaryViolation $failure) {
            $outcome = $failure->failure->value;
        }

        file_put_contents($result, $outcome, LOCK_EX);
        exit(0);
    } catch (Throwable) {
        file_put_contents($result, 'child_failure', LOCK_EX);
        exit(1);
    }
}

function forkEffectBoundaryCutoverWorker(
    WriterFence $next,
    string $applicationName,
    string $ready,
    string $go,
    string $result,
): int {
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the cutover race worker.');
    }

    if ($pid !== 0) {
        return $pid;
    }

    try {
        /** @var DatabaseManager $database */
        $database = app('db');

        foreach (array_keys($database->getConnections()) as $connectionName) {
            $database->purge((string) $connectionName);
        }

        $connection = $database->connection('integration_operations_boundary_test');
        $connection->selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
        file_put_contents($ready, 'ready', LOCK_EX);
        waitForEffectBoundaryFile($go, 10);

        try {
            app(DatabaseWriterFenceAuthority::class)->cutover(
                IntegrationScope::of('fixture_dispatch', 'tenant:boundary'),
                new OperationType('fixture_dispatch.message.deliver'),
                1,
                $next,
            );
            $outcome = 'cutover';
        } catch (WriterFenceCutoverBlocked) {
            $outcome = 'blocked';
        }

        file_put_contents($result, $outcome, LOCK_EX);
        exit(0);
    } catch (Throwable) {
        file_put_contents($result, 'child_failure', LOCK_EX);
        exit(1);
    }
}
