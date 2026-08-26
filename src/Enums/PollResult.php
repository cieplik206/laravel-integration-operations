<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum PollResult: string
{
    case Completed = 'completed';
    case ProviderRejected = 'provider_rejected';
    case Wait = 'wait';
    case SendRequired = 'send_required';
    case ManualReview = 'manual_review';
}
