<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeOperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeOperationSnapshotBatch;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

/** @api */
interface AuthoritativeScopedOperationQuery
{
    public const int MaximumBatchSize = AuthoritativeOperationSnapshotBatch::MaximumOperationIds;

    public function find(OperationId $operationId): ?AuthoritativeOperationSnapshot;

    /** @param iterable<OperationId> $operationIds */
    public function findMany(iterable $operationIds): AuthoritativeOperationSnapshotBatch;
}
