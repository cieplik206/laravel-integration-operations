<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Contracts\ScopedOperationQuery;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshotBatch;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Symfony\Component\Uid\Ulid;

function queryOperationId(string $lastCharacter): OperationId
{
    return new OperationId("01ARZ3NDEKTSV4RRFFQ69G5FA{$lastCharacter}");
}

function pendingQuerySnapshot(OperationId $operationId, IntegrationScope $scope): OperationSnapshot
{
    return new OperationSnapshot(
        operationId: $operationId,
        scope: $scope,
        operationType: new OperationType("{$scope->provider->value}.record.fetch"),
        status: OperationStatus::Pending,
        resultAvailability: ResultAvailability::NotReady,
        result: null,
        context: IntegrationContext::make(),
    );
}

it('builds a non-empty deduplicated and bounded integration scope set', function (): void {
    $first = IntegrationScope::of('fixture_catalog', 'primary');
    $second = IntegrationScope::of('fixture_catalog', 'secondary');
    $scopes = IntegrationScopeSet::from([$first, $first, $second]);

    expect($scopes->scopes())->toHaveCount(2)
        ->and($scopes->count())->toBe(2)
        ->and($scopes->contains(IntegrationScope::of('fixture_catalog', 'primary')))->toBeTrue()
        ->and($scopes->contains(IntegrationScope::of('fixture_catalog', 'foreign')))->toBeFalse()
        ->and(fn (): IntegrationScopeSet => IntegrationScopeSet::from([]))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty');

    $tooManyScopes = (function (): Generator {
        for ($index = 0; $index <= IntegrationScopeSet::MaximumScopes; $index++) {
            yield IntegrationScope::of('fixture_catalog', "connection_{$index}");
        }
    })();

    expect(fn (): IntegrationScopeSet => IntegrationScopeSet::from($tooManyScopes))
        ->toThrow(InvalidArgumentException::class, 'maximum size');
});

it('returns requested snapshots in input order with an explicit missing set', function (): void {
    $allowedScope = IntegrationScope::of('fixture_catalog', 'primary');
    $allowedScopes = IntegrationScopeSet::from([$allowedScope]);
    $firstId = queryOperationId('V');
    $secondId = queryOperationId('W');
    $missingId = queryOperationId('X');
    $batch = new OperationSnapshotBatch(
        allowedScopes: $allowedScopes,
        requestedOperationIds: [$secondId, $missingId, $firstId, $secondId],
        snapshots: [
            pendingQuerySnapshot($firstId, $allowedScope),
            pendingQuerySnapshot($secondId, $allowedScope),
        ],
    );

    expect(array_map(
        fn (OperationSnapshot $snapshot): string => $snapshot->operationId->value,
        $batch->snapshots(),
    ))->toBe([$secondId->value, $firstId->value])
        ->and(array_map(
            fn (OperationId $operationId): string => $operationId->value,
            $batch->missingOperationIds(),
        ))->toBe([$missingId->value]);
});

it('treats foreign and unrequested snapshots as missing without exposing them', function (): void {
    $allowedScope = IntegrationScope::of('fixture_catalog', 'primary');
    $foreignScope = IntegrationScope::of('fixture_catalog', 'foreign');
    $requestedForeignId = queryOperationId('V');
    $unrequestedId = queryOperationId('W');
    $batch = new OperationSnapshotBatch(
        allowedScopes: IntegrationScopeSet::from([$allowedScope]),
        requestedOperationIds: [$requestedForeignId],
        snapshots: [
            pendingQuerySnapshot($requestedForeignId, $foreignScope),
            pendingQuerySnapshot($unrequestedId, $allowedScope),
        ],
    );

    expect($batch->snapshots())->toBe([])
        ->and($batch->missingOperationIds())->toHaveCount(1)
        ->and($batch->missingOperationIds()[0]->equals($requestedForeignId))->toBeTrue();
});

it('caps batch inputs and freezes the scoped query signatures', function (): void {
    $allowedScopes = IntegrationScopeSet::from([
        IntegrationScope::of('fixture_catalog', 'primary'),
    ]);
    $tooManyOperationIds = (function (): Generator {
        for ($index = 0; $index <= OperationSnapshotBatch::MaximumOperationIds; $index++) {
            yield new OperationId((string) new Ulid);
        }
    })();

    expect(fn (): OperationSnapshotBatch => new OperationSnapshotBatch(
        $allowedScopes,
        $tooManyOperationIds,
        [],
    ))->toThrow(InvalidArgumentException::class, 'maximum size')
        ->and(OperationQuery::class)->toBeInterface()
        ->and(ScopedOperationQuery::class)->toBeInterface()
        ->and(ScopedOperationQuery::MaximumBatchSize)->toBe(500);
});
