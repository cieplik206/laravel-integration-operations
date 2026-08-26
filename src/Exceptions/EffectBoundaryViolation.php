<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use Cieplik206\IntegrationOperations\Enums\EffectBoundaryFailure;
use LogicException;

final class EffectBoundaryViolation extends LogicException
{
    public function __construct(public readonly EffectBoundaryFailure $failure)
    {
        parent::__construct(match ($failure) {
            EffectBoundaryFailure::Forbidden => 'The effect boundary is forbidden for this operation.',
            EffectBoundaryFailure::AlreadyOpened => 'The effect boundary was already opened for this operation.',
            EffectBoundaryFailure::LeaseLost => 'The operation lease was lost before the effect boundary.',
            EffectBoundaryFailure::InvalidState => 'The operation is not eligible to open its effect boundary.',
            EffectBoundaryFailure::WriterFenceRejected => 'The accepted writer fence no longer permits this effect boundary.',
        });
    }
}
