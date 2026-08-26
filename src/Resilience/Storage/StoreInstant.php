<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience\Storage;

use InvalidArgumentException;

/** @internal */
final readonly class StoreInstant
{
    public function __construct(public int $milliseconds)
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('Store instant cannot be negative.');
        }
    }
}
