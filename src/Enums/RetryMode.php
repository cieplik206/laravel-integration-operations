<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum RetryMode: string
{
    case ReadSafe = 'read_safe';
    case EffectAware = 'effect_aware';
}
