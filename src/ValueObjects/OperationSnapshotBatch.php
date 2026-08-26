<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class OperationSnapshotBatch
{
    public const MaximumOperationIds = 500;

    /** @var list<OperationSnapshot> */
    private array $snapshots;

    /** @var list<OperationId> */
    private array $missingOperationIds;

    /**
     * @param  iterable<OperationId>  $requestedOperationIds
     * @param  iterable<OperationSnapshot>  $snapshots
     */
    public function __construct(
        IntegrationScopeSet $allowedScopes,
        iterable $requestedOperationIds,
        iterable $snapshots,
    ) {
        $requestedById = self::requestedById($requestedOperationIds);
        $snapshotsById = self::allowedSnapshotsById($allowedScopes, $requestedById, $snapshots);
        $orderedSnapshots = [];
        $missingOperationIds = [];

        foreach ($requestedById as $operationIdValue => $operationId) {
            if (! isset($snapshotsById[$operationIdValue])) {
                $missingOperationIds[] = $operationId;

                continue;
            }

            $orderedSnapshots[] = $snapshotsById[$operationIdValue];
        }

        $this->snapshots = $orderedSnapshots;
        $this->missingOperationIds = $missingOperationIds;
    }

    /** @return list<OperationSnapshot> */
    public function snapshots(): array
    {
        return $this->snapshots;
    }

    /** @return list<OperationId> */
    public function missingOperationIds(): array
    {
        return $this->missingOperationIds;
    }

    /**
     * @param  iterable<mixed>  $operationIds
     * @return array<string, OperationId>
     */
    private static function requestedById(iterable $operationIds): array
    {
        $requestedById = [];

        foreach ($operationIds as $operationId) {
            if (! $operationId instanceof OperationId) {
                throw new InvalidArgumentException('Operation snapshot batch contains an invalid requested ID.');
            }

            $requestedById[$operationId->value] = $operationId;

            if (count($requestedById) > self::MaximumOperationIds) {
                throw new InvalidArgumentException('Operation snapshot batch exceeds its maximum size.');
            }
        }

        return $requestedById;
    }

    /**
     * @param  array<string, OperationId>  $requestedById
     * @param  iterable<mixed>  $snapshots
     * @return array<string, OperationSnapshot>
     */
    private static function allowedSnapshotsById(
        IntegrationScopeSet $allowedScopes,
        array $requestedById,
        iterable $snapshots,
    ): array {
        $snapshotsById = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof OperationSnapshot) {
                throw new InvalidArgumentException('Operation snapshot batch contains an invalid snapshot.');
            }

            $operationIdValue = $snapshot->operationId->value;

            if (! isset($requestedById[$operationIdValue]) || ! $allowedScopes->contains($snapshot->scope)) {
                continue;
            }

            if (isset($snapshotsById[$operationIdValue])) {
                throw new InvalidArgumentException('Operation snapshot batch contains duplicate snapshots.');
            }

            $snapshotsById[$operationIdValue] = $snapshot;
        }

        return $snapshotsById;
    }
}
