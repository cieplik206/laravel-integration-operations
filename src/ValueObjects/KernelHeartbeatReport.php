<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

/** @internal */
final readonly class KernelHeartbeatReport
{
    /** @param list<DispatchBatch> $dispatchBatches */
    public function __construct(
        public array $dispatchBatches,
        public int $recovered,
        public int $quarantined,
        public int $deferred,
        public int $skipped,
    ) {}

    public function dispatched(): int
    {
        return array_sum(array_map(
            static fn (DispatchBatch $batch): int => $batch->dispatched,
            $this->dispatchBatches,
        ));
    }

    public function dispatchFailures(): int
    {
        return array_sum(array_map(
            static fn (DispatchBatch $batch): int => $batch->failures(),
            $this->dispatchBatches,
        ));
    }
}
