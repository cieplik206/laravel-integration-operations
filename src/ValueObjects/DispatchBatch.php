<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @internal */
final readonly class DispatchBatch
{
    /** @var list<OperationId> */
    public array $failedOperationIds;

    /** @param array<array-key, mixed> $failedOperationIds */
    public function __construct(
        public IntegrationScope $scope,
        public int $scanned,
        public int $dispatched,
        array $failedOperationIds,
    ) {
        if ($scanned < 0
            || $dispatched < 0
            || $dispatched > $scanned
            || count($failedOperationIds) !== $scanned - $dispatched) {
            throw new InvalidArgumentException('Dispatch batch counts are invalid.');
        }

        foreach ($failedOperationIds as $operationId) {
            if (! $operationId instanceof OperationId) {
                throw new InvalidArgumentException('Dispatch batch contains an invalid operation ID.');
            }
        }

        /** @var list<OperationId> $normalizedFailedOperationIds */
        $normalizedFailedOperationIds = array_values($failedOperationIds);
        $this->failedOperationIds = $normalizedFailedOperationIds;
    }

    public function failures(): int
    {
        return count($this->failedOperationIds);
    }
}
