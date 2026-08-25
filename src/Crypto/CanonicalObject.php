<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;

/** @api */
final readonly class CanonicalObject
{
    /** @var array<string, mixed> */
    public array $values;

    /** @param array<mixed> $values */
    public function __construct(array $values)
    {
        $this->values = ImmutableValueSanitizer::canonicalMap($values, 'Canonical object');
    }

    public static function empty(): self
    {
        return new self([]);
    }
}
