<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @internal */
final readonly class LeaseRecoveryBatch
{
    public function __construct(
        public int $scanned,
        public int $recovered,
        public int $quarantined,
        public int $deferred,
        public int $skipped,
        public ?LeaseRecoveryCursor $nextCursor,
        public bool $exhausted,
        public int $notificationFailures = 0,
    ) {
        if ($scanned < 0
            || $recovered < 0
            || $quarantined < 0
            || $deferred < 0
            || $skipped < 0
            || $scanned !== $recovered + $quarantined + $deferred + $skipped
            || $notificationFailures < 0
            || $notificationFailures > $quarantined + $deferred
            || (($scanned === 0) !== ($nextCursor === null))
            || ($scanned === 0 && ! $exhausted)) {
            throw new InvalidArgumentException('Lease recovery batch counters are inconsistent.');
        }
    }
}
