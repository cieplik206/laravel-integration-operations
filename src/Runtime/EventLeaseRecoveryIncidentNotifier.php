<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryIncident;
use Illuminate\Contracts\Events\Dispatcher;

/** @internal */
final readonly class EventLeaseRecoveryIncidentNotifier implements LeaseRecoveryIncidentNotifier
{
    public function __construct(private Dispatcher $events) {}

    public function notify(LeaseRecoveryIncident $incident): void
    {
        $this->events->dispatch($incident);
    }
}
