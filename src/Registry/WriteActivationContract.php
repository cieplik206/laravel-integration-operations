<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Enums\WriteActivation;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class WriteActivationContract
{
    private const int MaximumSlots = 64;

    /** @var array<string, WriteActivation> */
    public array $writeActivationSlots;

    /** @param array<string, WriteActivation> $writeActivationSlots */
    public function __construct(array $writeActivationSlots)
    {
        $slots = ImmutableValueSanitizer::stringList(
            array_keys($writeActivationSlots),
            'Write activation slots',
        );
        $activations = ImmutableValueSanitizer::enumList(
            array_values($writeActivationSlots),
            WriteActivation::class,
            'Write activation modes',
        );

        if ($slots === [] || count($slots) > self::MaximumSlots) {
            throw new InvalidArgumentException('Write activation contract must contain a bounded non-empty slot map.');
        }

        foreach ($slots as $slot) {
            if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $slot) !== 1) {
                throw new InvalidArgumentException('Write activation slot is invalid.');
            }
        }

        /** @var array<string, WriteActivation> $normalized */
        $normalized = array_combine($slots, $activations);
        ksort($normalized, SORT_STRING);
        $this->writeActivationSlots = $normalized;
    }

    public static function disabled(string ...$writeActivationSlots): self
    {
        return new self(array_fill_keys($writeActivationSlots, WriteActivation::Disabled));
    }

    public static function immediate(string ...$writeActivationSlots): self
    {
        return new self(array_fill_keys($writeActivationSlots, WriteActivation::ImmediateExecute));
    }

    public function forWriteActivationSlot(string $writeActivationSlot): ?WriteActivation
    {
        return $this->writeActivationSlots[$writeActivationSlot] ?? null;
    }

    public function requireWriteActivationSlot(string $writeActivationSlot): WriteActivation
    {
        return $this->forWriteActivationSlot($writeActivationSlot)
            ?? throw new InvalidArgumentException('Payload selected an undeclared write activation slot.');
    }
}
