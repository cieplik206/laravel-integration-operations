<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Context;

use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;
use JsonException;

/** @api */
final readonly class IntegrationContextCodec
{
    public function __construct(
        private IntegrationContextConstraints $constraints = new IntegrationContextConstraints,
        private CanonicalJsonV1 $canonicalJson = new CanonicalJsonV1,
    ) {}

    public function encode(IntegrationContext $context): string
    {
        $validated = IntegrationContext::make(
            $context->correlationId,
            $context->attributes,
            $this->constraints,
        );
        $data = $validated->toArray();
        $data['attributes'] = new CanonicalObject($validated->attributes);

        $encoded = $this->canonicalJson->encode(new CanonicalObject($data));

        if (strlen($encoded) > $this->constraints->maximumBytes) {
            throw new InvalidArgumentException('Integration context exceeds its encoded byte limit.');
        }

        return $encoded;
    }

    /** @throws JsonException */
    public function decode(string $encoded): IntegrationContext
    {
        if (strlen($encoded) > $this->constraints->maximumBytes) {
            throw new InvalidArgumentException('Integration context exceeds its encoded byte limit.');
        }

        $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || array_is_list($data) || array_keys($data) !== ['attributes', 'correlation_id', 'version']) {
            throw new InvalidArgumentException('Integration context envelope is invalid.');
        }

        if (($data['version'] ?? null) !== IntegrationContext::Version) {
            throw new InvalidArgumentException('Unsupported integration context version.');
        }

        $correlationId = $data['correlation_id'] ?? null;
        $attributes = $data['attributes'] ?? null;

        if (($correlationId !== null && ! is_string($correlationId)) || ! is_array($attributes) || array_is_list($attributes) && $attributes !== []) {
            throw new InvalidArgumentException('Integration context fields are invalid.');
        }

        /** @var array<string, bool|int|string|null> $attributes */
        return IntegrationContext::make($correlationId, $attributes, $this->constraints);
    }
}
