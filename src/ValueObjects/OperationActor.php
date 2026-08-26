<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/** @api */
final readonly class OperationActor
{
    private ?SensitiveParameterValue $sensitiveReference;

    public function __construct(
        public string $category,
        #[SensitiveParameter]
        ?string $reference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,31}$/D', $category) !== 1) {
            throw new InvalidArgumentException('Operation actor category is invalid.');
        }

        if ($reference !== null
            && ($reference === ''
                || strlen($reference) > 512
                || preg_match('//u', $reference) !== 1
                || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $reference) === 1)) {
            throw new InvalidArgumentException('Operation actor reference is invalid.');
        }

        $this->sensitiveReference = $reference === null ? null : new SensitiveParameterValue($reference);
    }

    public static function application(): self
    {
        return new self('application');
    }

    public function reference(): ?string
    {
        $reference = $this->sensitiveReference?->getValue();

        if ($reference !== null && ! is_string($reference)) {
            throw new LogicException('Operation actor reference storage is corrupted.');
        }

        return $reference;
    }

    /** @return array{category: string, reference: string|null} */
    public function __debugInfo(): array
    {
        return [
            'category' => $this->category,
            'reference' => $this->sensitiveReference === null ? null : '[REDACTED]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Operation actors cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Operation actors cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Operation actors cannot be cloned.');
    }
}
