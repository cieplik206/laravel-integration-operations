<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;
use Stringable;

/** @api */
final readonly class OperationType implements Stringable
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Operation type must contain a provider and at least two qualified segments.');
        }
    }

    public function belongsTo(ProviderKey $provider): bool
    {
        return str_starts_with($this->value, "{$provider->value}.");
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
