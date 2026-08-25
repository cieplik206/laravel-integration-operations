<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum RetryDecision: string
{
    case Retry = 'retry';
    case Reconcile = 'reconcile';
    case Fail = 'fail';
    case ManualReview = 'manual_review';
}
