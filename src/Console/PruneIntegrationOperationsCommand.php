<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Console;

use Cieplik206\IntegrationOperations\Retention\DatabaseOperationRetentionPruner;
use Cieplik206\IntegrationOperations\Retention\OperationRetentionReport;
use Illuminate\Console\Command;
use Throwable;

/** @internal */
final class PruneIntegrationOperationsCommand extends Command
{
    protected $signature = 'integration-operations:prune
        {--force : Permanently prune eligible payloads and attempt diagnostics}';

    protected $description = 'Preview or execute bounded integration operation data retention';

    public function handle(DatabaseOperationRetentionPruner $pruner): int
    {
        try {
            $force = $this->option('force') === true;
            $report = $force ? $pruner->prune() : $pruner->preview();

            $this->displayReport($report);

            if (! $force) {
                $this->components->warn('Preview only. Re-run with --force to permanently prune one bounded batch.');

                return self::SUCCESS;
            }

            $this->components->info('Eligible sensitive data was pruned. Terminal operation tombstones were preserved.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Integration operation retention failed; sensitive failure details were withheld.');

            return self::FAILURE;
        }
    }

    private function displayReport(OperationRetentionReport $report): void
    {
        $this->table(['category', 'eligible', 'pruned'], [
            ['raw payload envelopes', $report->eligiblePayloads, $report->prunedPayloads],
            ['attempt diagnostics', $report->eligibleAttemptDiagnostics, $report->prunedAttemptDiagnostics],
            ['terminal tombstones older than five years', $report->protectedTerminalTombstones, 0],
        ]);
    }
}
