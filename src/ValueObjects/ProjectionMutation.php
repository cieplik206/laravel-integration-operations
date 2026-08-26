<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class ProjectionMutation
{
    private const int MaximumFields = 32;

    private const int MaximumStringBytes = 16_384;

    private const int MaximumMutationBytes = 65_536;

    /** @var array<string, null|bool|int|string> */
    public array $identity;

    /** @var array<string, null|bool|int|string> */
    public array $values;

    /**
     * @param  array<string, null|bool|int|string>  $identity
     * @param  array<string, null|bool|int|string>  $values
     */
    public function __construct(
        public string $targetId,
        array $identity,
        public ?int $expectedVersion,
        array $values,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $targetId) !== 1
            || $identity === []
            || $values === []
            || count($identity) > self::MaximumFields
            || count($values) > self::MaximumFields
            || $expectedVersion !== null && $expectedVersion < 0) {
            throw new InvalidArgumentException('Projection mutation shape is invalid.');
        }

        $this->identity = $this->boundedScalarMap($identity, 'Projection mutation identity');
        $this->values = $this->boundedScalarMap($values, 'Projection mutation values');
        $encoded = (new CanonicalJsonV1)->encode(new CanonicalObject([
            'identity' => $this->identity,
            'values' => $this->values,
        ]));

        if (strlen($encoded) > self::MaximumMutationBytes) {
            throw new InvalidArgumentException('Projection mutation exceeds its byte limit.');
        }
    }

    /**
     * @param  array<string, null|bool|int|string>  $values
     * @return array<string, null|bool|int|string>
     */
    private function boundedScalarMap(array $values, string $subject): array
    {
        $values = ImmutableValueSanitizer::canonicalMap($values, $subject);

        foreach ($values as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || ! (is_null($value) || is_bool($value) || is_int($value) || is_string($value))
                || is_string($value) && strlen($value) > self::MaximumStringBytes) {
                throw new InvalidArgumentException("{$subject} contains an invalid field.");
            }
        }

        /** @var array<string, null|bool|int|string> $values */
        return $values;
    }
}
