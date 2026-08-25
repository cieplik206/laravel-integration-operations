<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** @api */
final class CanonicalJsonV1
{
    public const Version = 1;

    /** @throws JsonException */
    public function encode(mixed $value): string
    {
        return json_encode(
            $this->normalize(ImmutableValueSanitizer::canonicalValue($value, 'Canonical JSON')),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function normalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof CanonicalObject) {
            return $this->normalizeObject($value->values);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Canonical JSON accepts only null, booleans, integers, strings, lists, and string-key maps.');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $this->normalizeObject($value);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>|stdClass
     */
    private function normalizeObject(array $values): array|stdClass
    {
        if ($values === []) {
            return new stdClass;
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Canonical JSON object keys must be strings.');
            }

            $normalized[$key] = $value;
        }

        ksort($normalized, SORT_STRING);

        foreach ($normalized as $key => $value) {
            $normalized[$key] = $this->normalize($value);
        }

        return $normalized;
    }
}
