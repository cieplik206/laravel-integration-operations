<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use InvalidArgumentException;
use Stringable;

/** @api */
final readonly class EndpointFamily implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Endpoint family must be a bounded lowercase identifier.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
