<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum EffectState: string
{
    case NotStarted = 'not_started';
    case PossiblyApplied = 'possibly_applied';
    case NotApplied = 'not_applied';
    case Applied = 'applied';
}
