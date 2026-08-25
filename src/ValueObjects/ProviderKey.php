<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;
use Stringable;

/** @api */
final readonly class ProviderKey implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Provider key must be a lowercase provider identifier.');
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
