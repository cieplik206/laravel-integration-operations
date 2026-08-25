<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum FailureDisposition: string
{
    case RetryableRead = 'retryable_read';
    case RequestNotStarted = 'request_not_started';
    case Uncertain = 'uncertain';
    case NotApplied = 'not_applied';
    case Permanent = 'permanent';
    case ManualReview = 'manual_review';
}
