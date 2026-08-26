<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum ManualResolutionDecision: string
{
    case Reconcile = 'reconcile';
    case ConfirmSucceeded = 'confirm_succeeded';
    case ConfirmFailed = 'confirm_failed';
    case Cancel = 'cancel';
}
