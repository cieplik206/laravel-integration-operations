<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\PendingOperationDispatcher;
use Cieplik206\IntegrationOperations\ValueObjects\KernelHeartbeatReport;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal */
final readonly class KernelHeartbeat
{
    public function __construct(
        private ConfiguredIntegrationScopes $scopes,
        private PendingOperationDispatcher $dispatcher,
        private OperationLeaseManager $leases,
        private Repository $config,
    ) {}

    public function run(): KernelHeartbeatReport
    {
        $dispatchBatches = [];
        $recovered = 0;
        $quarantined = 0;
        $deferred = 0;
        $skipped = 0;
        $limit = $this->batchLimit();

        foreach ($this->scopes->all() as $scope) {
            $dispatchBatches[] = $this->dispatcher->dispatch($scope, $limit);
            $recovery = $this->leases->recoverExpired($scope, $limit, max($limit, $this->scanLimit()));
            $recovered += $recovery->recovered;
            $quarantined += $recovery->quarantined;
            $deferred += $recovery->deferred;
            $skipped += $recovery->skipped;
        }

        return new KernelHeartbeatReport(
            $dispatchBatches,
            $recovered,
            $quarantined,
            $deferred,
            $skipped,
        );
    }

    private function batchLimit(): int
    {
        $configured = $this->config->get('integration-operations.queues.claim_batch_per_connection', 25);

        if (! is_int($configured) || $configured < 1 || $configured > 500) {
            throw new InvalidArgumentException('Integration operation heartbeat batch limit is invalid.');
        }

        return $configured;
    }

    private function scanLimit(): int
    {
        $configured = $this->config->get('integration-operations.scheduler.recovery_scan_limit', 500);

        if (! is_int($configured) || $configured < 1 || $configured > 5000) {
            throw new InvalidArgumentException('Integration operation recovery scan limit is invalid.');
        }

        return $configured;
    }
}
