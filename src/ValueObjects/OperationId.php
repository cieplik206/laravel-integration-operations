<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;
use Stringable;
use Symfony\Component\Uid\Ulid;

/** @api */
final readonly class OperationId implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if (! Ulid::isValid($value)) {
            throw new InvalidArgumentException('Operation ID must be a valid ULID.');
        }

        $this->value = (string) Ulid::fromString($value);
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
