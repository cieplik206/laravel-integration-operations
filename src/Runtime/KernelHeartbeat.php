<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\PendingOperationDispatcher;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
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
        $scopes = $this->scopes->all();
        $connectionLimit = $this->connectionBatchLimit();
        $dispatchLimits = $this->dispatchLimits($scopes, $connectionLimit);

        foreach ($scopes as $index => $scope) {
            $dispatchBatches[] = $this->dispatcher->dispatch($scope, $dispatchLimits[$index]);
            $recovery = $this->leases->recoverExpired(
                $scope,
                $connectionLimit,
                max($connectionLimit, $this->scanLimit()),
            );
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

    private function connectionBatchLimit(): int
    {
        $configured = $this->config->get('integration-operations.queues.claim_batch_per_connection', 25);

        if (! is_int($configured) || $configured < 1 || $configured > 500) {
            throw new InvalidArgumentException('Integration operation heartbeat batch limit is invalid.');
        }

        return $configured;
    }

    /**
     * @param  list<IntegrationScope>  $scopes
     * @return list<int>
     */
    private function dispatchLimits(array $scopes, int $connectionLimit): array
    {
        $providerLimit = $this->providerBatchLimit();
        $scopeCounts = [];

        foreach ($scopes as $scope) {
            $provider = $scope->provider->value;
            $scopeCounts[$provider] = ($scopeCounts[$provider] ?? 0) + 1;
        }

        foreach ($scopeCounts as $scopeCount) {
            if ($scopeCount > $providerLimit) {
                throw new InvalidArgumentException(
                    'Integration operation provider dispatch budget cannot serve every configured connection.',
                );
            }
        }

        $positions = [];
        $limits = [];

        foreach ($scopes as $scope) {
            $provider = $scope->provider->value;
            $scopeCount = $scopeCounts[$provider];
            $position = $positions[$provider] ?? 0;
            $fairShare = intdiv($providerLimit, $scopeCount);

            if ($position < $providerLimit % $scopeCount) {
                $fairShare++;
            }

            $limits[] = min($connectionLimit, $fairShare);
            $positions[$provider] = $position + 1;
        }

        return $limits;
    }

    private function providerBatchLimit(): int
    {
        $configured = $this->config->get('integration-operations.queues.claim_batch_per_provider', 100);

        if (! is_int($configured) || $configured < 1 || $configured > 5000) {
            throw new InvalidArgumentException('Integration operation per-provider dispatch budget is invalid.');
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
