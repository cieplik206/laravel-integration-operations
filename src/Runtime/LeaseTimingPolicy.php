<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal */
final readonly class LeaseTimingPolicy
{
    public function __construct(private Repository $config) {}

    public function leaseSeconds(): int
    {
        return $this->validated()['lease'];
    }

    public function heartbeatSeconds(): int
    {
        return $this->validated()['heartbeat'];
    }

    public function remoteCallBudgetSeconds(): int
    {
        return $this->validated()['remote_call_budget'];
    }

    /** @return array{lease: int, heartbeat: int, remote_call_budget: int} */
    private function validated(): array
    {
        $lease = $this->integer('seconds');
        $heartbeat = $this->integer('heartbeat_seconds');
        $connectTimeout = $this->integer('connect_timeout_seconds');
        $requestTimeout = $this->integer('request_timeout_seconds');
        $safetyMargin = $this->integer('safety_margin_seconds');
        $remoteCallBudget = $connectTimeout + $requestTimeout + $safetyMargin;

        if ($lease < 2 || $lease > 86400
            || $heartbeat < 1 || $heartbeat >= $lease
            || $connectTimeout < 1 || $connectTimeout > 3600
            || $requestTimeout < 1 || $requestTimeout > 43200
            || $safetyMargin < 1 || $safetyMargin > 3600
            || $lease <= $remoteCallBudget) {
            throw new InvalidArgumentException('Integration operation lease timing policy is unsafe.');
        }

        return [
            'lease' => $lease,
            'heartbeat' => $heartbeat,
            'remote_call_budget' => $remoteCallBudget,
        ];
    }

    private function integer(string $key): int
    {
        $value = $this->config->get("integration-operations.leases.{$key}");

        if (! is_int($value)) {
            throw new InvalidArgumentException('Integration operation lease timing configuration is invalid.');
        }

        return $value;
    }
}
