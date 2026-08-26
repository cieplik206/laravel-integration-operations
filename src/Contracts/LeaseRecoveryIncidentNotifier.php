<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryIncident;

/** @internal */
interface LeaseRecoveryIncidentNotifier
{
    public function notify(LeaseRecoveryIncident $incident): void;
}
