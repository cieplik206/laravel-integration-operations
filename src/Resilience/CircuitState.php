<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

/** @api */
enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
