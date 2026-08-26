<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class AuthoritativeOperationSnapshotBatch
{
    public const int MaximumOperationIds = 500;

    /** @var list<AuthoritativeOperationSnapshot> */
    private array $snapshots;

    /** @var list<OperationId> */
    private array $missingOperationIds;

    /**
     * @param  iterable<OperationId>  $requestedOperationIds
     * @param  iterable<AuthoritativeOperationSnapshot>  $snapshots
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

    /** @return list<AuthoritativeOperationSnapshot> */
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
                throw new InvalidArgumentException('Authoritative snapshot batch contains an invalid requested ID.');
            }

            $requestedById[$operationId->value] = $operationId;

            if (count($requestedById) > self::MaximumOperationIds) {
                throw new InvalidArgumentException('Authoritative snapshot batch exceeds its maximum size.');
            }
        }

        return $requestedById;
    }

    /**
     * @param  array<string, OperationId>  $requestedById
     * @param  iterable<mixed>  $snapshots
     * @return array<string, AuthoritativeOperationSnapshot>
     */
    private static function allowedSnapshotsById(
        IntegrationScopeSet $allowedScopes,
        array $requestedById,
        iterable $snapshots,
    ): array {
        $snapshotsById = [];

        foreach ($snapshots as $snapshot) {
            if (! $snapshot instanceof AuthoritativeOperationSnapshot) {
                throw new InvalidArgumentException('Authoritative snapshot batch contains an invalid snapshot.');
            }

            $operationIdValue = $snapshot->operationId->value;

            if (! isset($requestedById[$operationIdValue]) || ! $allowedScopes->contains($snapshot->scope)) {
                continue;
            }

            if (isset($snapshotsById[$operationIdValue])) {
                throw new InvalidArgumentException('Authoritative snapshot batch contains duplicate snapshots.');
            }

            $snapshotsById[$operationIdValue] = $snapshot;
        }

        return $snapshotsById;
    }
}
