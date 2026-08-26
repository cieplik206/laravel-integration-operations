<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/** @api */
final readonly class WriterFence
{
    private ?SensitiveParameterValue $sensitiveCohort;

    public function __construct(
        public int $generation,
        public OwnerMode $ownerMode,
        #[SensitiveParameter]
        ?string $cohort = null,
    ) {
        if ($generation < 1) {
            throw new InvalidArgumentException('Writer generation must be positive.');
        }

        if ($cohort !== null && ($cohort === '' || strlen($cohort) > 512 || preg_match('//u', $cohort) !== 1)) {
            throw new InvalidArgumentException('Writer cohort is invalid.');
        }

        if ($ownerMode === OwnerMode::CanaryWrite && $cohort === null) {
            throw new InvalidArgumentException('Canary writer fences require a deterministic cohort.');
        }

        $this->sensitiveCohort = $cohort === null ? null : new SensitiveParameterValue($cohort);
    }

    public function cohort(): ?string
    {
        $cohort = $this->sensitiveCohort?->getValue();

        if ($cohort !== null && ! is_string($cohort)) {
            throw new LogicException('Writer cohort storage is corrupted.');
        }

        return $cohort;
    }

    /** @return array{generation: int, owner_mode: string, cohort: string|null} */
    public function __debugInfo(): array
    {
        return [
            'generation' => $this->generation,
            'owner_mode' => $this->ownerMode->value,
            'cohort' => $this->sensitiveCohort === null ? null : '[REDACTED]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Writer fences cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Writer fences cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Writer fences cannot be cloned.');
    }
}
