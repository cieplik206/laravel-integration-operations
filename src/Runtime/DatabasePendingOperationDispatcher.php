<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\PendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\RuntimeTransactionActive;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\DispatchBatch;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabasePendingOperationDispatcher implements PendingOperationDispatcher
{
    public function __construct(
        private KernelDatabase $database,
        private DurableAcceptanceNotifier $notifier,
        private Repository $config,
    ) {}

    public function dispatch(IntegrationScope $scope, int $limit): DispatchBatch
    {
        $maximum = $this->maximumBatchSize();

        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException('Integration operation dispatch batch limit is invalid.');
        }

        $transactionBaseline = $this->transactionBaseline();
        $candidates = $this->candidates($scope, $limit);
        $dispatched = 0;
        $failed = [];

        foreach ($candidates as $candidate) {
            $operationId = $this->candidateOperationId($candidate, $scope);

            try {
                $this->notifier->notify(new OperationReceipt(
                    $operationId,
                    $scope,
                    new OperationType($candidate->operation_type),
                    true,
                ));
                $this->assertTransactionBaseline($transactionBaseline);
                $this->recordDispatch($candidate, $scope, $operationId);
                $this->assertTransactionBaseline($transactionBaseline);
                $dispatched++;
            } catch (Throwable) {
                $failed[] = $operationId;
                $this->restoreTransactionBaseline($transactionBaseline);
            }
        }

        return new DispatchBatch($scope, count($candidates), $dispatched, $failed);
    }

    /** @return list<stdClass> */
    private function candidates(IntegrationScope $scope, int $limit): array
    {
        $connection = $this->database->connection();
        $redispatchAfterSeconds = $this->redispatchAfterSeconds();
        $eligible = $connection->table('integration_operations as operation')
            ->leftJoin(
                'integration_operation_authoritative_states as authoritative',
                'authoritative.operation_id',
                '=',
                'operation.id',
            )
            ->select([
                'operation.id',
                'operation.operation_type',
                'operation.status',
                'operation.row_version',
                'operation.priority',
                'operation.accepted_at',
            ])
            ->selectRaw(<<<'SQL'
                ROW_NUMBER() OVER (
                    PARTITION BY operation.operation_type, operation.priority
                    ORDER BY operation.accepted_at, operation.id
                ) AS operation_type_rank
                SQL)
            ->where('operation.provider', $scope->provider->value)
            ->where('operation.connection_key', $scope->connection->value)
            ->where(function ($query) use ($connection): void {
                $query->where('operation.status', OperationStatus::Pending->value)
                    ->orWhere(function ($due) use ($connection): void {
                        $due->whereIn('operation.status', [
                            OperationStatus::RetryWait->value,
                            OperationStatus::Uncertain->value,
                        ])->where(function ($deadline) use ($connection): void {
                            $deadline->whereNull('operation.next_attempt_at')
                                ->orWhere(
                                    'operation.next_attempt_at',
                                    '<=',
                                    $connection->raw('clock_timestamp()'),
                                );
                        });
                    })
                    ->orWhere(function ($poll) use ($connection): void {
                        $poll->where('operation.status', OperationStatus::PollWait->value)
                            ->whereNotNull('authoritative.next_poll_at')
                            ->where(
                                'authoritative.next_poll_at',
                                '<=',
                                $connection->raw('clock_timestamp()'),
                            )
                            ->where('authoritative.result_availability', 'not_ready')
                            ->whereNull('authoritative.terminal_proof_kind');
                    });
            })
            ->where(function ($query) use ($redispatchAfterSeconds): void {
                $query->whereNull('operation.last_dispatched_at')
                    ->orWhereRaw(
                        "operation.last_dispatched_at <= clock_timestamp() - (? * INTERVAL '1 second')",
                        [$redispatchAfterSeconds],
                    );
            })
            ->whereNull('operation.lease_owner')
            ->whereNull('operation.lease_token_sha256')
            ->whereNull('operation.active_attempt_id');

        $rows = $connection->query()
            ->fromSub($eligible, 'candidate')
            ->select([
                'candidate.id',
                'candidate.operation_type',
                'candidate.status',
                'candidate.row_version',
            ])
            ->orderByDesc('candidate.priority')
            ->orderBy('candidate.operation_type_rank')
            ->orderBy('candidate.accepted_at')
            ->orderBy('candidate.id')
            ->limit($limit)
            ->get();

        return array_values($rows->all());
    }

    private function candidateOperationId(stdClass $candidate, IntegrationScope $scope): OperationId
    {
        if (! is_string($candidate->id ?? null)
            || ! is_string($candidate->operation_type ?? null)
            || ! is_string($candidate->status ?? null)
            || ! is_int($candidate->row_version ?? null)) {
            throw new OperationPersistenceFailed;
        }

        $operationType = new OperationType($candidate->operation_type);

        if (! $operationType->belongsTo($scope->provider)) {
            throw new OperationPersistenceFailed;
        }

        return new OperationId($candidate->id);
    }

    private function recordDispatch(
        stdClass $candidate,
        IntegrationScope $scope,
        OperationId $operationId,
    ): void {
        if (! is_string($candidate->status ?? null) || ! is_int($candidate->row_version ?? null)) {
            throw new OperationPersistenceFailed;
        }

        $connection = $this->database->connection();
        $updated = $connection->table('integration_operations')
            ->where('id', $operationId->value)
            ->where('provider', $scope->provider->value)
            ->where('connection_key', $scope->connection->value)
            ->where('status', $candidate->status)
            ->where('row_version', $candidate->row_version)
            ->whereNull('lease_owner')
            ->whereNull('active_attempt_id')
            ->update([
                'dispatch_attempts' => $connection->raw('dispatch_attempts + 1'),
                'last_dispatched_at' => $connection->raw('clock_timestamp()'),
                'updated_at' => $connection->raw('clock_timestamp()'),
            ]);

        if ($updated !== 1) {
            throw new OperationPersistenceFailed;
        }
    }

    private function maximumBatchSize(): int
    {
        $configured = $this->config->get('integration-operations.queues.claim_batch_per_connection', 25);

        if (! is_int($configured) || $configured < 1 || $configured > 500) {
            throw new InvalidArgumentException('Integration operation per-connection dispatch budget is invalid.');
        }

        return $configured;
    }

    private function redispatchAfterSeconds(): int
    {
        $configured = $this->config->get('integration-operations.queues.redispatch_after_seconds', 60);

        if (! is_int($configured) || $configured < 1 || $configured > 86_400) {
            throw new InvalidArgumentException('Integration operation redispatch delay is invalid.');
        }

        return $configured;
    }

    /** @return array<string, int> */
    private function transactionBaseline(): array
    {
        $baseline = $this->database->transactionLevels();

        if (array_filter($baseline, static fn (int $level): bool => $level !== 0) !== []) {
            throw new RuntimeTransactionActive;
        }

        return $baseline;
    }

    /** @param array<string, int> $baseline */
    private function assertTransactionBaseline(array $baseline): void
    {
        if ($this->database->transactionLevels() !== $baseline) {
            throw new RuntimeTransactionActive;
        }
    }

    /** @param array<string, int> $baseline */
    private function restoreTransactionBaseline(array $baseline): void
    {
        try {
            $this->database->restoreTransactionLevels($baseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }
    }
}
