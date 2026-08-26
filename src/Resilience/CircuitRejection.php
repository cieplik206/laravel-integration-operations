<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

/** @api */
enum CircuitRejection: string
{
    case Open = 'open';
    case ProbeRequired = 'probe_required';
    case ProbeInProgress = 'probe_in_progress';
    case NotOpen = 'not_open';
    case InFlightCapacity = 'in_flight_capacity';
    case PolicyMismatch = 'policy_mismatch';
}
