<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum OperationTelemetryEvent: string
{
    case Accepted = 'accepted';
    case Claimed = 'claimed';
    case Polled = 'polled';
    case Reconciled = 'reconciled';
    case Projected = 'projected';
    case ManualReview = 'manual_review';
    case FenceDenied = 'fence_denied';
    case Terminalized = 'terminalized';
}
