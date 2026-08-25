<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Support;

use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionObject;
use ReflectionReference;
use UnitEnum;

/** @internal */
final class ImmutableValueSanitizer
{
    private const MaximumDepth = 64;

    private const MaximumValues = 10_000;

    public static function canonicalValue(mixed $value, string $subject = 'Canonical value'): mixed
    {
        $visiting = [];
        $validated = [];
        $visitedValues = 0;

        return self::sanitizeValue(
            $value,
            false,
            $subject,
            $visiting,
            $validated,
            $visitedValues,
            0,
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    public static function canonicalMap(array $values, string $subject): array
    {
        if ($values !== [] && array_is_list($values)) {
            throw new InvalidArgumentException("{$subject} must use string keys.");
        }

        $sanitized = self::canonicalValue($values, $subject);

        if (! is_array($sanitized)) {
            throw new InvalidArgumentException("{$subject} must be a map.");
        }

        /** @var array<string, mixed> $sanitized */
        return $sanitized;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function stringList(array $values, string $subject): array
    {
        $sanitized = self::deeplyImmutableValue($values, $subject);

        if (! is_array($sanitized) || ! array_is_list($sanitized)) {
            throw new InvalidArgumentException("{$subject} must be a list.");
        }

        foreach ($sanitized as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("{$subject} must contain only strings.");
            }

            if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1) {
                throw new InvalidArgumentException("{$subject} must contain only printable single-line strings.");
            }
        }

        /** @var list<string> $sanitized */
        return $sanitized;
    }

    /**
     * @template T of UnitEnum
     *
     * @param  array<mixed>  $values
     * @param  class-string<T>  $enum
     * @return list<T>
     */
    public static function enumList(array $values, string $enum, string $subject): array
    {
        $sanitized = self::deeplyImmutableValue($values, $subject);

        if (! is_array($sanitized) || ! array_is_list($sanitized)) {
            throw new InvalidArgumentException("{$subject} must be a list.");
        }

        foreach ($sanitized as $value) {
            if (! $value instanceof $enum) {
                throw new InvalidArgumentException("{$subject} contains an invalid enum value.");
            }
        }

        /** @var list<T> $sanitized */
        return $sanitized;
    }

    public static function assertDeeplyImmutable(object $value, string $subject): void
    {
        self::deeplyImmutableValue($value, $subject);
    }

    private static function deeplyImmutableValue(mixed $value, string $subject): mixed
    {
        $visiting = [];
        $validated = [];
        $visitedValues = 0;

        return self::sanitizeValue(
            $value,
            true,
            $subject,
            $visiting,
            $validated,
            $visitedValues,
            0,
        );
    }

    /**
     * @param  array<int, true>  $visiting
     * @param  array<int, true>  $validated
     */
    private static function sanitizeValue(
        mixed $value,
        bool $allowImmutableObjects,
        string $subject,
        array &$visiting,
        array &$validated,
        int &$visitedValues,
        int $depth,
    ): mixed {
        $visitedValues++;

        if ($depth > self::MaximumDepth || $visitedValues > self::MaximumValues) {
            throw new InvalidArgumentException("{$subject} graph exceeds its validation bounds.");
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException("{$subject} strings must contain valid UTF-8.");
            }

            return $value;
        }

        if (is_array($value)) {
            return self::sanitizeArray(
                $value,
                $allowImmutableObjects,
                $subject,
                $visiting,
                $validated,
                $visitedValues,
                $depth,
            );
        }

        if (! is_object($value)) {
            throw new InvalidArgumentException("{$subject} may contain only canonical immutable values.");
        }

        if (! $allowImmutableObjects) {
            if (! $value instanceof CanonicalObject) {
                throw new InvalidArgumentException("{$subject} may contain only canonical JSON values.");
            }

            self::sanitizeCanonicalObject(
                $value,
                $subject,
                $visiting,
                $validated,
                $visitedValues,
                $depth,
            );

            return $value;
        }

        if ($value instanceof UnitEnum || $value::class === DateTimeImmutable::class) {
            return $value;
        }

        self::sanitizeReadonlyObject(
            $value,
            $subject,
            $visiting,
            $validated,
            $visitedValues,
            $depth,
        );

        return $value;
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int, true>  $visiting
     * @param  array<int, true>  $validated
     * @return array<mixed>
     */
    private static function sanitizeArray(
        array $values,
        bool $allowImmutableObjects,
        string $subject,
        array &$visiting,
        array &$validated,
        int &$visitedValues,
        int $depth,
    ): array {
        $isList = array_is_list($values);

        foreach ($values as $key => $_value) {
            if (ReflectionReference::fromArrayElement($values, $key) !== null) {
                throw new InvalidArgumentException("{$subject} arrays must not contain references.");
            }

            if (! $isList && ! is_string($key)) {
                throw new InvalidArgumentException("{$subject} maps must use string keys.");
            }

            if (is_string($key) && preg_match('//u', $key) !== 1) {
                throw new InvalidArgumentException("{$subject} map keys must contain valid UTF-8.");
            }
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[$key] = self::sanitizeValue(
                $value,
                $allowImmutableObjects,
                $subject,
                $visiting,
                $validated,
                $visitedValues,
                $depth + 1,
            );
        }

        return $sanitized;
    }

    /**
     * @param  array<int, true>  $visiting
     * @param  array<int, true>  $validated
     */
    private static function sanitizeCanonicalObject(
        CanonicalObject $value,
        string $subject,
        array &$visiting,
        array &$validated,
        int &$visitedValues,
        int $depth,
    ): void {
        $objectId = spl_object_id($value);

        if (isset($validated[$objectId])) {
            return;
        }

        if (isset($visiting[$objectId])) {
            throw new InvalidArgumentException("{$subject} must not contain cyclic object graphs.");
        }

        $visiting[$objectId] = true;
        self::sanitizeArray(
            $value->values,
            false,
            $subject,
            $visiting,
            $validated,
            $visitedValues,
            $depth + 1,
        );
        unset($visiting[$objectId]);
        $validated[$objectId] = true;
    }

    /**
     * @param  array<int, true>  $visiting
     * @param  array<int, true>  $validated
     */
    private static function sanitizeReadonlyObject(
        object $value,
        string $subject,
        array &$visiting,
        array &$validated,
        int &$visitedValues,
        int $depth,
    ): void {
        $objectId = spl_object_id($value);

        if (isset($validated[$objectId])) {
            return;
        }

        if (isset($visiting[$objectId])) {
            throw new InvalidArgumentException("{$subject} must not contain cyclic object graphs.");
        }

        $reflection = new ReflectionObject($value);

        if (! $reflection->isFinal() || ! $reflection->isReadOnly()) {
            throw new InvalidArgumentException("{$subject} and nested objects must be final readonly value objects.");
        }

        $visiting[$objectId] = true;

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (! $property->isInitialized($value)) {
                throw new InvalidArgumentException("{$subject} properties must be initialized before publication.");
            }

            self::sanitizeValue(
                $property->getValue($value),
                true,
                $subject,
                $visiting,
                $validated,
                $visitedValues,
                $depth + 1,
            );
        }

        unset($visiting[$objectId]);
        $validated[$objectId] = true;
    }
}
