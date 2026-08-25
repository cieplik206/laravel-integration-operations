<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum AmbiguousEffectAction: string
{
    case NotApplicable = 'not_applicable';
    case Reconcile = 'reconcile';
    case ManualReview = 'manual_review';
}
