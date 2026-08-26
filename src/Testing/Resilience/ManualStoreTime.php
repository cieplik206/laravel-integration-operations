<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Resilience;

use Cieplik206\IntegrationOperations\Resilience\Storage\StoreInstant;
use InvalidArgumentException;

/** @api */
final class ManualStoreTime
{
    public function __construct(private int $milliseconds = 1_700_000_000_000)
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('Manual store time cannot be negative.');
        }
    }

    public function now(): StoreInstant
    {
        return new StoreInstant($this->milliseconds);
    }

    public function advanceMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0 || $this->milliseconds > PHP_INT_MAX - $milliseconds) {
            throw new InvalidArgumentException('Manual store time advance is invalid.');
        }

        $this->milliseconds += $milliseconds;
    }

    public function setMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('Manual store time cannot be negative.');
        }

        $this->milliseconds = $milliseconds;
    }
}
