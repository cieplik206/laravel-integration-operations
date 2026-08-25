<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class SafeOperationFailure
{
    public const Version = 1;

    public function __construct(
        public string $code,
        public string $summary,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Safe failure code is invalid.');
        }

        if ($summary === '' || strlen($summary) > 512 || preg_match('//u', $summary) !== 1) {
            throw new InvalidArgumentException('Safe failure summary must be valid UTF-8 containing at most 512 bytes.');
        }

        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $summary) === 1) {
            throw new InvalidArgumentException('Safe failure summary must be a printable single line.');
        }
    }

    /** @return array{version: int, code: string, summary: string} */
    public function toArray(): array
    {
        return [
            'version' => self::Version,
            'code' => $this->code,
            'summary' => $this->summary,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $expectedKeys = ['version', 'code', 'summary'];

        if (array_diff(array_keys($data), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($data)) !== []
            || ($data['version'] ?? null) !== self::Version
            || ! is_string($data['code'] ?? null)
            || ! is_string($data['summary'] ?? null)) {
            throw new InvalidArgumentException('Safe failure envelope is invalid.');
        }

        return new self($data['code'], $data['summary']);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->summary === $other->summary;
    }
}
