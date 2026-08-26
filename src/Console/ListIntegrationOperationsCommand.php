<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Console;

use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use DateTimeInterface;
use Illuminate\Console\Command;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** @internal */
final class ListIntegrationOperationsCommand extends Command
{
    private const int MaximumLimit = 500;

    protected $signature = 'integration-operations:list
        {--provider= : Required provider key}
        {--connection= : Required connection key}
        {--status= : Optional exact lifecycle status}
        {--limit=50 : Maximum number of rows (1-500)}';

    protected $description = 'List safe integration operation metadata inside one exact provider connection scope';

    public function handle(KernelDatabase $database): int
    {
        try {
            $scope = $this->scope();
            $status = $this->status();
            $limit = $this->limit();
            $query = $database->connection()
                ->table('integration_operations')
                ->where('provider', $scope->provider->value)
                ->where('connection_key', $scope->connection->value);

            if ($status instanceof OperationStatus) {
                $query->where('status', $status->value);
            }

            $operations = $query
                ->orderByDesc('id')
                ->limit($limit)
                ->get([
                    'id',
                    'operation_type',
                    'status',
                    'disposition',
                    'effect_state',
                    'created_at',
                    'updated_at',
                ]);
            $rows = $operations->map(fn (mixed $operation): array => $this->row($operation))->all();
        } catch (InvalidArgumentException) {
            $this->components->error('Provider, connection, status, or limit is invalid.');

            return self::INVALID;
        } catch (Throwable) {
            $this->components->error('Integration operation metadata could not be read safely.');

            return self::FAILURE;
        }

        $this->table(
            ['operation id', 'type', 'status', 'disposition', 'effect', 'created', 'updated'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function scope(): IntegrationScope
    {
        $provider = $this->option('provider');
        $connection = $this->option('connection');

        if (! is_string($provider) || $provider === '' || ! is_string($connection) || $connection === '') {
            throw new InvalidArgumentException;
        }

        return IntegrationScope::of($provider, $connection);
    }

    private function status(): ?OperationStatus
    {
        $status = $this->option('status');

        if ($status === null) {
            return null;
        }

        if (! is_string($status) || $status === '') {
            throw new InvalidArgumentException;
        }

        return OperationStatus::tryFrom($status) ?? throw new InvalidArgumentException;
    }

    private function limit(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > self::MaximumLimit) {
            throw new InvalidArgumentException;
        }

        return $limit;
    }

    /** @return array{string, string, string, string, string, string, string} */
    private function row(mixed $operation): array
    {
        if (! $operation instanceof stdClass) {
            throw new InvalidArgumentException;
        }

        return [
            $this->text($operation->id ?? null),
            $this->text($operation->operation_type ?? null),
            $this->text($operation->status ?? null),
            $this->text($operation->disposition ?? null),
            $this->text($operation->effect_state ?? null),
            $this->text($operation->created_at ?? null),
            $this->text($operation->updated_at ?? null),
        ];
    }

    private function text(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException;
        }

        return (string) $value;
    }
}
