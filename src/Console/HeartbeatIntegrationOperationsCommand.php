<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Console;

use Cieplik206\IntegrationOperations\Runtime\KernelHeartbeat;
use Illuminate\Console\Command;
use Throwable;

/** @internal */
final class HeartbeatIntegrationOperationsCommand extends Command
{
    protected $signature = 'integration-operations:heartbeat';

    protected $description = 'Dispatch due integration operations and recover expired leases for configured scopes';

    public function handle(KernelHeartbeat $heartbeat): int
    {
        try {
            $report = $heartbeat->run();
        } catch (Throwable) {
            $this->components->error('Integration operations heartbeat failed safely.');

            return self::FAILURE;
        }

        $this->components->info('Integration operations heartbeat completed.');
        $this->table(
            ['dispatched', 'dispatch failures', 'recovered', 'quarantined', 'deferred', 'skipped'],
            [[
                $report->dispatched(),
                $report->dispatchFailures(),
                $report->recovered,
                $report->quarantined,
                $report->deferred,
                $report->skipped,
            ]],
        );

        return $report->dispatchFailures() === 0 ? self::SUCCESS : self::FAILURE;
    }
}
