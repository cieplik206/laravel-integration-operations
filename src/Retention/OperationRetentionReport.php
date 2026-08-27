<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Retention;

/** @internal */
final readonly class OperationRetentionReport
{
    public function __construct(
        public int $eligiblePayloads,
        public int $eligibleAttemptDiagnostics,
        public int $protectedTerminalTombstones,
        public int $prunedPayloads = 0,
        public int $prunedAttemptDiagnostics = 0,
    ) {}
}
