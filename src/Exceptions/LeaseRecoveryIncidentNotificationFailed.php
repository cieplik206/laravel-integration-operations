<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

/** @internal */
final class LeaseRecoveryIncidentNotificationFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A lease recovery incident notification failed after the durable decision.');
    }
}
