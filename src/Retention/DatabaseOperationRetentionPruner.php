<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Retention;

use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;

/** @internal */
final readonly class DatabaseOperationRetentionPruner
{
    /** @var list<string> */
    private const array TERMINAL_STATUSES = ['succeeded', 'failed', 'cancelled'];

    public function __construct(
        private KernelDatabase $database,
        private Clock $clock,
        private OperationRetentionPolicy $policy,
    ) {}

    public function preview(): OperationRetentionReport
    {
        $connection = $this->postgresConnection();
        $now = $this->clock->now();

        return new OperationRetentionReport(
            eligiblePayloads: $this->eligiblePayloads($connection, $now)->count(),
            eligibleAttemptDiagnostics: $this->eligibleAttemptDiagnostics($connection, $now)->count(),
            protectedTerminalTombstones: $this->protectedTerminalTombstones($connection, $now),
        );
    }

    public function prune(): OperationRetentionReport
    {
        $connection = $this->postgresConnection();
        $now = $this->clock->now();
        $preview = new OperationRetentionReport(
            eligiblePayloads: $this->eligiblePayloads($connection, $now)->count(),
            eligibleAttemptDiagnostics: $this->eligibleAttemptDiagnostics($connection, $now)->count(),
            protectedTerminalTombstones: $this->protectedTerminalTombstones($connection, $now),
        );

        return $connection->transaction(function () use ($connection, $now, $preview): OperationRetentionReport {
            $connection->statement("SELECT pg_advisory_xact_lock(hashtext('integration-operations:retention'))");

            $payloadIds = $this->eligiblePayloads($connection, $now)
                ->orderBy('payloads.id')
                ->limit($this->policy->batchSize)
                ->lockForUpdate()
                ->pluck('payloads.id')
                ->all();

            $prunedPayloads = $payloadIds === [] ? 0 : $connection
                ->table('integration_operation_payloads')
                ->whereIn('id', $payloadIds)
                ->whereNull('payload_pruned_at')
                ->update([
                    'payload_key_version' => null,
                    'payload_cipher' => null,
                    'payload_ciphertext' => null,
                    'payload_ciphertext_sha256' => null,
                    'payload_pruned_at' => $now,
                ]);

            $attemptIds = $this->eligibleAttemptDiagnostics($connection, $now)
                ->orderBy('attempts.id')
                ->limit($this->policy->batchSize)
                ->lockForUpdate()
                ->pluck('attempts.id')
                ->all();

            $prunedAttemptDiagnostics = 0;

            if ($attemptIds !== []) {
                $connection->statement("SET LOCAL integration_operations.retention = 'on'");

                $prunedAttemptDiagnostics = $connection
                    ->table('integration_operation_attempts')
                    ->whereIn('id', $attemptIds)
                    ->whereNull('diagnostics_pruned_at')
                    ->update([
                        'transport' => null,
                        'request_method' => null,
                        'target_template' => null,
                        'request_fingerprint' => null,
                        'request_started_at' => null,
                        'response_received_at' => null,
                        'response_code' => null,
                        'provider_request_id' => null,
                        'error_category' => null,
                        'error_code' => null,
                        'safe_metadata' => null,
                        'diagnostics_pruned_at' => $now,
                    ]);
            }

            return new OperationRetentionReport(
                eligiblePayloads: $preview->eligiblePayloads,
                eligibleAttemptDiagnostics: $preview->eligibleAttemptDiagnostics,
                protectedTerminalTombstones: $preview->protectedTerminalTombstones,
                prunedPayloads: $prunedPayloads,
                prunedAttemptDiagnostics: $prunedAttemptDiagnostics,
            );
        });
    }

    private function postgresConnection(): Connection
    {
        $connection = $this->database->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Integration operation retention requires PostgreSQL.');
        }

        return $connection;
    }

    private function eligiblePayloads(Connection $connection, DateTimeImmutable $now): Builder
    {
        return $connection
            ->table('integration_operation_payloads as payloads')
            ->join('integration_operations as operations', 'operations.id', '=', 'payloads.operation_id')
            ->whereIn('operations.status', self::TERMINAL_STATUSES)
            ->whereNotNull('operations.completed_at')
            ->where('operations.completed_at', '<=', $this->policy->rawPayloadCutoff($now))
            ->whereNull('payloads.payload_pruned_at');
    }

    private function eligibleAttemptDiagnostics(Connection $connection, DateTimeImmutable $now): Builder
    {
        return $connection
            ->table('integration_operation_attempts as attempts')
            ->join('integration_operations as operations', 'operations.id', '=', 'attempts.operation_id')
            ->whereIn('operations.status', self::TERMINAL_STATUSES)
            ->whereNotNull('operations.completed_at')
            ->whereNotNull('attempts.finished_at')
            ->where('attempts.finished_at', '<=', $this->policy->attemptDiagnosticsCutoff($now))
            ->whereNull('attempts.diagnostics_pruned_at');
    }

    private function protectedTerminalTombstones(Connection $connection, DateTimeImmutable $now): int
    {
        return $connection
            ->table('integration_operations')
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $this->policy->terminalTombstoneCutoff($now))
            ->count();
    }
}
