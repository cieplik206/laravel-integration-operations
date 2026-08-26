<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshotBatch;

/**
 * Read-only operation query which never distinguishes an absent ID from an ID outside its allowed scopes.
 *
 * @api
 */
interface ScopedOperationQuery
{
    public const MaximumBatchSize = OperationSnapshotBatch::MaximumOperationIds;

    public function find(OperationId $operationId): ?OperationSnapshot;

    /** @param iterable<OperationId> $operationIds */
    public function findMany(iterable $operationIds): OperationSnapshotBatch;
}
