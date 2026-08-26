<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/** @api */
final readonly class LocalReference
{
    private SensitiveParameterValue $sensitiveIdentifier;

    public function __construct(
        public string $type,
        #[SensitiveParameter]
        string $identifier,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Local reference type must be an allowlisted morph alias.');
        }

        if ($identifier === '' || strlen($identifier) > 512 || preg_match('//u', $identifier) !== 1) {
            throw new InvalidArgumentException('Local reference identifier is invalid.');
        }

        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $identifier) === 1) {
            throw new InvalidArgumentException('Local reference identifier must be a printable single line.');
        }

        $this->sensitiveIdentifier = new SensitiveParameterValue($identifier);
    }

    public function identifier(): string
    {
        $identifier = $this->sensitiveIdentifier->getValue();

        if (! is_string($identifier)) {
            throw new LogicException('Local reference storage is corrupted.');
        }

        return $identifier;
    }

    /** @return array{type: string, identifier: string} */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'identifier' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Local references cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Local references cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Local references cannot be cloned.');
    }
}
