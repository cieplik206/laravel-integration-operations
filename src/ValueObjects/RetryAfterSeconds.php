<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class RetryAfterSeconds
{
    public function __construct(public int $value)
    {
        if ($value < 0 || $value > 604_800) {
            throw new InvalidArgumentException('Relative retry-after hint is outside its bounded range.');
        }
    }
}
