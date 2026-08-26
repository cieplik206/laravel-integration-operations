<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Enums\EffectBoundaryFailure;
use InvalidArgumentException;

/** @internal */
final readonly class DatabaseEffectBoundaryDecision
{
    private function __construct(
        public bool $opened,
        public ?int $rowVersion,
        public ?EffectBoundaryFailure $failure,
    ) {
        if ($opened === ($failure !== null)
            || ($opened && ($rowVersion === null || $rowVersion < 1))
            || (! $opened && $rowVersion !== null)) {
            throw new InvalidArgumentException('Database effect-boundary decision is inconsistent.');
        }
    }

    public static function opened(int $rowVersion): self
    {
        return new self(true, $rowVersion, null);
    }

    public static function rejected(EffectBoundaryFailure $failure): self
    {
        return new self(false, null, $failure);
    }
}
