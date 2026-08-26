<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryIncident;

final class RecordingLeaseRecoveryIncidentNotifier implements LeaseRecoveryIncidentNotifier
{
    /** @var list<LeaseRecoveryIncident> */
    public array $incidents = [];

    public function notify(LeaseRecoveryIncident $incident): void
    {
        $this->incidents[] = $incident;
    }
}
