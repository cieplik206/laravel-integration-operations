<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class IntentIdentity
{
    public function __construct(
        public string $resourceType,
        public string $semanticSlot,
        public ?LocalReference $localReference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $resourceType) !== 1) {
            throw new InvalidArgumentException('Intent resource type is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $semanticSlot) !== 1) {
            throw new InvalidArgumentException('Intent semantic slot is invalid.');
        }
    }
}
