<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Enums\LeaseRecoveryDisposition;
use Cieplik206\IntegrationOperations\Runtime\LeaseRecoveryEntryOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryBatch;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryCursor;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryIncident;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use InvalidArgumentException;

it('normalizes trusted PostgreSQL cursor instants and round trips one canonical versioned payload', function (): void {
    $scope = IntegrationScope::of('fixture_catalog', 'tenant:cursor');
    $operationId = new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $zeroFraction = LeaseRecoveryCursor::fromDatabase(
        $scope,
        '2026-08-26 10:20:30+00',
        $operationId,
    );
    $shortFractionAndOffset = LeaseRecoveryCursor::fromDatabase(
        $scope,
        '2026-08-26 12:20:30.12+02',
        $operationId,
    );

    expect($zeroFraction->leaseExpiresAt)->toBe('2026-08-26 10:20:30.000000+00:00')
        ->and($shortFractionAndOffset->leaseExpiresAt)->toBe('2026-08-26 10:20:30.120000+00:00')
        ->and(LeaseRecoveryCursor::fromArray($shortFractionAndOffset->toArray())->toArray())
        ->toBe($shortFractionAndOffset->toArray());
});

it('rejects relative, noncanonical, oversized, unknown-version, and extra cursor data', function (): void {
    $scope = IntegrationScope::of('fixture_catalog', 'tenant:cursor');
    $operationId = new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $canonical = new LeaseRecoveryCursor(
        $scope,
        '2026-08-26 10:20:30.000000+00:00',
        $operationId,
    );
    $payload = $canonical->toArray();

    expect(fn () => new LeaseRecoveryCursor($scope, 'tomorrow', $operationId))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LeaseRecoveryCursor($scope, '2026-08-26 12:20:30.000000+02:00', $operationId))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => LeaseRecoveryCursor::fromArray([...$payload, 'version' => 2]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => LeaseRecoveryCursor::fromArray([...$payload, 'extra' => true]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => LeaseRecoveryCursor::fromArray([...$payload, 'connection' => str_repeat('x', 129)]))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces exact recovery batch accounting and notification failure bounds', function (): void {
    $cursor = new LeaseRecoveryCursor(
        IntegrationScope::of('fixture_catalog', 'tenant:cursor'),
        '2026-08-26 10:20:30.000000+00:00',
        new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
    );

    $empty = new LeaseRecoveryBatch(0, 0, 0, 0, 0, null, true);
    $populated = new LeaseRecoveryBatch(2, 1, 0, 1, 0, $cursor, true, 1);

    expect($empty->scanned)->toBe(0)
        ->and($populated->notificationFailures)->toBe(1)
        ->and(fn () => new LeaseRecoveryBatch(2, 1, 0, 0, 0, $cursor, true))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LeaseRecoveryBatch(0, 0, 0, 0, 0, $cursor, true))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LeaseRecoveryBatch(1, 1, 0, 0, 0, $cursor, true, 1))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces disposition and incident consistency for every recovery entry', function (): void {
    $scope = IntegrationScope::of('fixture_catalog', 'tenant:cursor');
    $operationId = new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $quarantined = new LeaseRecoveryIncident($operationId, $scope, 'integrity_fixture', true);
    $deferred = new LeaseRecoveryIncident($operationId, $scope, 'runtime_fixture', false);

    $recovered = new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Recovered);
    $quarantinedOutcome = new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Quarantined, $quarantined);
    $deferredOutcome = new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Deferred, $deferred);

    expect($recovered->incident)->toBeNull()
        ->and($quarantinedOutcome->incident)->toBe($quarantined)
        ->and($deferredOutcome->incident)->toBe($deferred)
        ->and(fn () => new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Recovered, $deferred))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Quarantined, $deferred))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new LeaseRecoveryEntryOutcome(LeaseRecoveryDisposition::Deferred, $quarantined))
        ->toThrow(InvalidArgumentException::class);
});
