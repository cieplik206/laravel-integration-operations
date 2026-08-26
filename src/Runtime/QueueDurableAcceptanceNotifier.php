<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Jobs\ProcessIntegrationOperation;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal */
final readonly class QueueDurableAcceptanceNotifier implements DurableAcceptanceNotifier
{
    public function __construct(
        private Dispatcher $bus,
        private Repository $config,
    ) {}

    public function notify(OperationReceipt $receipt): void
    {
        $job = (new ProcessIntegrationOperation($receipt->operationId->value))
            ->onQueue($this->queue($receipt->scope->provider->value));
        $connection = $this->config->get('integration-operations.queues.connection');

        if ($connection !== null) {
            if (! is_string($connection) || $connection === '') {
                throw new InvalidArgumentException('Integration operation queue connection is invalid.');
            }

            $job->onConnection($connection);
        }

        $this->bus->dispatch($job);
    }

    private function queue(string $provider): string
    {
        $routes = $this->config->get('integration-operations.queues.routes', []);
        $allowlist = $this->config->get('integration-operations.queues.allowlist', []);

        if (! is_array($routes) || ! is_array($allowlist) || ! array_is_list($allowlist)) {
            throw new InvalidArgumentException('Integration operation queue routing configuration is invalid.');
        }

        $queue = $routes[$provider] ?? $routes['default'] ?? null;

        if (! is_string($queue)
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $queue) !== 1
            || ! in_array($queue, $allowlist, true)) {
            throw new InvalidArgumentException('Integration operation queue route is not allowlisted.');
        }

        return $queue;
    }
}
