<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryIncident;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Database\Connection;
use RuntimeException;

final class HostileLeaseRecoveryIncidentNotifier implements LeaseRecoveryIncidentNotifier
{
    public int $calls = 0;

    /** @var list<array<string, string>> */
    public array $observedStatuses = [];

    /** @param list<OperationId> $operationIds */
    public function __construct(
        private readonly Connection $observer,
        /** @var list<Connection> */
        private readonly array $connectionsToLeak,
        private readonly array $operationIds,
    ) {}

    public function notify(LeaseRecoveryIncident $incident): void
    {
        $this->calls++;
        $statuses = $this->observer->table('integration_operations')
            ->whereIn('id', array_map(
                static fn (OperationId $operationId): string => $operationId->value,
                $this->operationIds,
            ))
            ->pluck('status', 'id')
            ->all();

        /** @var array<string, string> $statuses */
        $this->observedStatuses[] = $statuses;

        if ($this->calls !== 1) {
            return;
        }

        foreach ($this->connectionsToLeak as $connection) {
            $connection->beginTransaction();
        }

        throw new RuntimeException('Hostile incident listener fixture.');
    }
}
