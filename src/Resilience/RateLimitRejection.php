<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

/** @api */
enum RateLimitRejection: string
{
    case Cooldown = 'cooldown';
    case QuotaExceeded = 'quota_exceeded';
    case PolicyMismatch = 'policy_mismatch';
}
