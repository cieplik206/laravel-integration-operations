<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;
use Stringable;

/** @api */
final readonly class Sha256Digest implements Stringable
{
    public function __construct(public string $hex)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1) {
            throw new InvalidArgumentException('SHA-256 digest must be 64 lowercase hexadecimal characters.');
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hex, $other->hex);
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
