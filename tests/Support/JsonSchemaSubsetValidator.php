<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

final class JsonSchemaSubsetValidator
{
    /**
     * Validates the JSON Schema keywords used by the versioned SPI contract.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function validate(mixed $value, array $schema): array
    {
        return $this->validateNode($value, $schema, $schema, '$');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $rootSchema
     * @return list<string>
     */
    private function validateNode(mixed $value, array $schema, array $rootSchema, string $path): array
    {
        $reference = $schema['$ref'] ?? null;

        if (is_string($reference)) {
            $resolved = $this->resolveReference($rootSchema, $reference);

            if ($resolved === null) {
                return ["{$path}: unresolved schema reference {$reference}"];
            }

            return $this->validateNode($value, $resolved, $rootSchema, $path);
        }

        $errors = [];
        $allOf = $schema['allOf'] ?? null;

        if (is_array($allOf)) {
            foreach ($allOf as $constraint) {
                if (! is_array($constraint)) {
                    continue;
                }

                /** @var array<string, mixed> $validatedConstraint */
                $validatedConstraint = $constraint;
                $errors = [
                    ...$errors,
                    ...$this->validateNode($value, $validatedConstraint, $rootSchema, $path),
                ];
            }
        }

        $oneOf = $schema['oneOf'] ?? null;

        if (is_array($oneOf)) {
            $matchingBranches = 0;

            foreach ($oneOf as $constraint) {
                if (! is_array($constraint)) {
                    continue;
                }

                /** @var array<string, mixed> $validatedConstraint */
                $validatedConstraint = $constraint;

                if ($this->validateNode($value, $validatedConstraint, $rootSchema, $path) === []) {
                    $matchingBranches++;
                }
            }

            if ($matchingBranches !== 1) {
                $errors[] = "{$path}: expected exactly one matching schema branch";
            }
        }

        $not = $schema['not'] ?? null;

        if (is_array($not)) {
            /** @var array<string, mixed> $validatedNot */
            $validatedNot = $not;

            if ($this->validateNode($value, $validatedNot, $rootSchema, $path) === []) {
                $errors[] = "{$path}: value matches a forbidden schema";
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path}: value does not match const";
        }

        $enum = $schema['enum'] ?? null;

        if (is_array($enum) && ! in_array($value, $enum, true)) {
            $errors[] = "{$path}: value is outside enum";
        }

        $type = $schema['type'] ?? null;

        if (is_string($type) && ! $this->matchesType($value, $type)) {
            return [...$errors, "{$path}: expected {$type}"];
        }

        if (is_string($value)) {
            $pattern = $schema['pattern'] ?? null;

            if (is_string($pattern) && preg_match("~{$pattern}~D", $value) !== 1) {
                $errors[] = "{$path}: string does not match pattern";
            }
        }

        if (is_int($value)) {
            $minimum = $schema['minimum'] ?? null;

            if (is_int($minimum) && $value < $minimum) {
                $errors[] = "{$path}: integer is below minimum";
            }
        }

        if (! is_array($value)) {
            return $errors;
        }

        if (array_is_list($value)) {
            return [...$errors, ...$this->validateList($value, $schema, $rootSchema, $path)];
        }

        return [...$errors, ...$this->validateObject($value, $schema, $rootSchema, $path)];
    }

    /**
     * @param  list<mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $rootSchema
     * @return list<string>
     */
    private function validateList(array $value, array $schema, array $rootSchema, string $path): array
    {
        $errors = [];
        $minimumItems = $schema['minItems'] ?? null;

        if (is_int($minimumItems) && count($value) < $minimumItems) {
            $errors[] = "{$path}: list has fewer than {$minimumItems} items";
        }

        $maximumItems = $schema['maxItems'] ?? null;

        if (is_int($maximumItems) && count($value) > $maximumItems) {
            $errors[] = "{$path}: list has more than {$maximumItems} items";
        }

        if (($schema['uniqueItems'] ?? false) === true && count($value) !== count(array_unique($value, SORT_REGULAR))) {
            $errors[] = "{$path}: list items are not unique";
        }

        $itemSchema = $schema['items'] ?? null;

        if (is_array($itemSchema)) {
            /** @var array<string, mixed> $validatedItemSchema */
            $validatedItemSchema = $itemSchema;

            foreach ($value as $index => $item) {
                $errors = [
                    ...$errors,
                    ...$this->validateNode($item, $validatedItemSchema, $rootSchema, "{$path}[{$index}]"),
                ];
            }
        }

        $contains = $schema['contains'] ?? null;

        if (is_array($contains)) {
            /** @var array<string, mixed> $validatedContains */
            $validatedContains = $contains;
            $containsMatch = false;

            foreach ($value as $item) {
                if ($this->validateNode($item, $validatedContains, $rootSchema, $path) === []) {
                    $containsMatch = true;

                    break;
                }
            }

            if (! $containsMatch) {
                $errors[] = "{$path}: list does not contain a matching item";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $rootSchema
     * @return list<string>
     */
    private function validateObject(array $value, array $schema, array $rootSchema, string $path): array
    {
        $errors = [];
        $required = $schema['required'] ?? [];

        if (is_array($required)) {
            foreach ($required as $property) {
                if (is_string($property) && ! array_key_exists($property, $value)) {
                    $errors[] = "{$path}: missing required property {$property}";
                }
            }
        }

        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            return $errors;
        }

        /** @var array<string, array<string, mixed>> $properties */
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $property) {
                if (! array_key_exists($property, $properties)) {
                    $errors[] = "{$path}: unexpected property {$property}";
                }
            }
        }

        foreach ($properties as $property => $propertySchema) {
            if (! array_key_exists($property, $value)) {
                continue;
            }

            $errors = [
                ...$errors,
                ...$this->validateNode($value[$property], $propertySchema, $rootSchema, "{$path}.{$property}"),
            ];
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $rootSchema
     * @return array<string, mixed>|null
     */
    private function resolveReference(array $rootSchema, string $reference): ?array
    {
        if (! str_starts_with($reference, '#/')) {
            return null;
        }

        $resolved = $rootSchema;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            $candidate = $resolved[$segment] ?? null;

            if (! is_array($candidate)) {
                return null;
            }

            /** @var array<string, mixed> $candidate */
            $resolved = $candidate;
        }

        return $resolved;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'array' => is_array($value) && array_is_list($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'object' => is_array($value) && ! array_is_list($value),
            'string' => is_string($value),
            default => false,
        };
    }
}
