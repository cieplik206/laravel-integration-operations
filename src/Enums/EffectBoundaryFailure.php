<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @internal */
enum EffectBoundaryFailure: string
{
    case Forbidden = 'forbidden';
    case AlreadyOpened = 'already_opened';
    case LeaseLost = 'lease_lost';
    case InvalidState = 'invalid_state';
    case WriterFenceRejected = 'writer_fence_rejected';
}
