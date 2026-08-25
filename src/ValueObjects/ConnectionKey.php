<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;
use Stringable;

/** @api */
final readonly class ConnectionKey implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Connection key contains unsupported characters.');
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
