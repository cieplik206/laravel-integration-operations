<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use InvalidArgumentException;

/** @api */
final readonly class PollingContract
{
    public function __construct(
        public int $maximumAttempts,
        public int $deadlineSeconds,
        public int $minimumIntervalSeconds,
        public int $maximumIntervalSeconds,
    ) {
        if ($maximumAttempts < 1 || $maximumAttempts > 10_000) {
            throw new InvalidArgumentException('Polling attempt budget is invalid.');
        }

        if ($deadlineSeconds < 1 || $deadlineSeconds > 604_800) {
            throw new InvalidArgumentException('Polling deadline is invalid.');
        }

        if ($minimumIntervalSeconds < 1
            || $maximumIntervalSeconds < $minimumIntervalSeconds
            || $maximumIntervalSeconds > $deadlineSeconds) {
            throw new InvalidArgumentException('Polling interval bounds are invalid.');
        }
    }
}
