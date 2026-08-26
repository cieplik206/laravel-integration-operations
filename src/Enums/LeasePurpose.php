<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum LeasePurpose: string
{
    case Execute = 'execute';
    case Reconcile = 'reconcile';
    case Poll = 'poll';
}
