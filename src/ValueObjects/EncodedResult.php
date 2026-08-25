<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class EncodedResult
{
    /** @var array<string, mixed> */
    public array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $resultType,
        public int $schemaVersion,
        array $payload,
    ) {
        if (! self::isValidResultType($resultType)) {
            throw new InvalidArgumentException('Result type is invalid.');
        }

        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('Result schema version must be positive.');
        }

        $this->payload = ImmutableValueSanitizer::canonicalMap($payload, 'Encoded result payload');
        (new CanonicalJsonV1)->encode(new CanonicalObject($this->payload));
    }

    public static function isValidResultType(string $resultType): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D', $resultType) === 1;
    }

    /** @return array{result_type: string, schema_version: int, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'result_type' => $this->resultType,
            'schema_version' => $this->schemaVersion,
            'payload' => $this->payload,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $expectedKeys = ['result_type', 'schema_version', 'payload'];
        $payload = $data['payload'] ?? null;

        if (array_diff(array_keys($data), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($data)) !== []
            || ! is_string($data['result_type'] ?? null)
            || ! is_int($data['schema_version'] ?? null)
            || ! is_array($payload)
            || (array_is_list($payload) && $payload !== [])) {
            throw new InvalidArgumentException('Encoded result envelope is invalid.');
        }

        /** @var array<string, mixed> $payload */
        return new self($data['result_type'], $data['schema_version'], $payload);
    }

    public function equals(self $other): bool
    {
        $canonicalJson = new CanonicalJsonV1;

        return $this->resultType === $other->resultType
            && $this->schemaVersion === $other->schemaVersion
            && $canonicalJson->encode(new CanonicalObject($this->payload))
                === $canonicalJson->encode(new CanonicalObject($other->payload));
    }
}
