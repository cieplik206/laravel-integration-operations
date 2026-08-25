<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Context;

use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class IntegrationContext
{
    public const Version = 1;

    /** @var array<string, bool|int|string|null> */
    public array $attributes;

    /** @param array<string, bool|int|string|null> $attributes */
    private function __construct(
        public ?string $correlationId,
        array $attributes,
    ) {
        /** @var array<string, bool|int|string|null> $sanitized */
        $sanitized = ImmutableValueSanitizer::canonicalMap($attributes, 'Integration context attributes');
        $this->attributes = $sanitized;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function make(
        ?string $correlationId = null,
        array $attributes = [],
        ?IntegrationContextConstraints $constraints = null,
    ): self {
        $limits = $constraints ?? new IntegrationContextConstraints;
        self::validateCorrelationId($correlationId, $limits);
        $validatedAttributes = self::validatedAttributes($attributes, $limits);
        $context = new self($correlationId, $validatedAttributes);

        $envelope = $context->toArray();
        $envelope['attributes'] = new CanonicalObject($validatedAttributes);

        if (strlen((new CanonicalJsonV1)->encode(new CanonicalObject($envelope))) > $limits->maximumBytes) {
            throw new InvalidArgumentException('Integration context exceeds its encoded byte limit.');
        }

        return $context;
    }

    public static function forWorkflow(
        string $workflowId,
        string $step,
        IntegrationContextConstraints $constraints,
    ): self {
        return self::make(
            correlationId: "workflow:{$workflowId}:{$step}",
            attributes: [
                'workflow_id' => $workflowId,
                'step' => $step,
            ],
            constraints: $constraints,
        );
    }

    /** @return array{version: int, correlation_id: string|null, attributes: array<string, bool|int|string|null>} */
    public function toArray(): array
    {
        return [
            'version' => self::Version,
            'correlation_id' => $this->correlationId,
            'attributes' => $this->attributes,
        ];
    }

    public function equals(self $other): bool
    {
        return (new CanonicalJsonV1)->encode(new CanonicalObject($this->toArray()))
            === (new CanonicalJsonV1)->encode(new CanonicalObject($other->toArray()));
    }

    private static function validateCorrelationId(?string $correlationId, IntegrationContextConstraints $constraints): void
    {
        if ($correlationId === null) {
            return;
        }

        if ($correlationId === '' || strlen($correlationId) > $constraints->maximumCorrelationIdBytes) {
            throw new InvalidArgumentException('Integration context correlation ID is invalid.');
        }

        self::assertNoControlCharacters($correlationId, 'correlation ID');
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, bool|int|string|null>
     */
    private static function validatedAttributes(array $attributes, IntegrationContextConstraints $constraints): array
    {
        $attributes = ImmutableValueSanitizer::canonicalMap($attributes, 'Integration context attributes');

        if (count($attributes) > $constraints->maximumAttributes) {
            throw new InvalidArgumentException('Integration context contains too many attributes.');
        }

        $validated = [];

        foreach ($attributes as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $key) !== 1 || strlen($key) > $constraints->maximumKeyBytes) {
                throw new InvalidArgumentException('Integration context contains an invalid key.');
            }

            $normalizedKey = str_replace(['-', '.'], '_', strtolower($key));

            foreach ($constraints->reservedKeyFragments as $reservedFragment) {
                if (str_contains($normalizedKey, strtolower($reservedFragment))) {
                    throw new InvalidArgumentException("Integration context key '{$key}' is reserved.");
                }
            }

            if (is_string($value) && strlen($value) > $constraints->maximumStringBytes) {
                throw new InvalidArgumentException("Integration context value '{$key}' is too large.");
            }

            if (is_string($value)) {
                self::assertNoControlCharacters($value, "value '{$key}'");
            }

            if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidArgumentException("Integration context value '{$key}' must be scalar or null.");
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    private static function assertNoControlCharacters(string $value, string $field): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException("Integration context {$field} must be valid UTF-8.");
        }

        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new InvalidArgumentException("Integration context {$field} contains control characters.");
        }
    }
}
