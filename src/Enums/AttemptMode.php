<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @internal */
enum AttemptMode: string
{
    case Execute = 'execute';
    case Reconcile = 'reconcile';
    case Poll = 'poll';
    case Dispatch = 'dispatch';
    case Recovery = 'recovery';
    case Projection = 'projection';
    case Operator = 'operator';
}
