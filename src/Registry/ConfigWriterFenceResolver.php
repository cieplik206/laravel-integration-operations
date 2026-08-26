<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal */
final readonly class ConfigWriterFenceResolver implements WriterFenceResolver
{
    public function __construct(private Repository $config) {}

    public function current(IntegrationScope $scope, OperationType $operationType): ?WriterFence
    {
        $records = $this->config->get('integration-operations.writer_fences', []);

        if (! is_array($records) || ! array_is_list($records)) {
            throw new InvalidArgumentException('Integration operation writer fence configuration is invalid.');
        }

        $resolved = null;

        foreach ($records as $record) {
            $candidate = $this->record($record);

            if ($candidate['provider'] !== $scope->provider->value
                || $candidate['connection'] !== $scope->connection->value
                || $candidate['operation_type'] !== $operationType->value) {
                continue;
            }

            if ($resolved !== null) {
                throw new InvalidArgumentException('Integration operation writer fence configuration is ambiguous.');
            }

            $resolved = new WriterFence(
                generation: $candidate['generation'],
                ownerMode: $candidate['owner_mode'],
                cohort: $candidate['cohort'],
            );
        }

        return $resolved;
    }

    /**
     * @return array{provider: string, connection: string, operation_type: string, generation: int, owner_mode: OwnerMode, cohort: string|null}
     */
    private function record(mixed $record): array
    {
        $expectedKeys = ['provider', 'connection', 'operation_type', 'generation', 'owner_mode', 'cohort'];

        if (! is_array($record)
            || array_diff(array_keys($record), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($record)) !== []
            || ! is_string($record['provider'] ?? null)
            || ! is_string($record['connection'] ?? null)
            || ! is_string($record['operation_type'] ?? null)
            || ! is_int($record['generation'] ?? null)
            || ! is_string($record['owner_mode'] ?? null)
            || (! is_string($record['cohort'] ?? null) && ($record['cohort'] ?? null) !== null)) {
            throw new InvalidArgumentException('Integration operation writer fence record is invalid.');
        }

        $ownerMode = OwnerMode::tryFrom($record['owner_mode']);

        if ($ownerMode === null) {
            throw new InvalidArgumentException('Integration operation writer fence owner mode is invalid.');
        }

        return [
            'provider' => $record['provider'],
            'connection' => $record['connection'],
            'operation_type' => $record['operation_type'],
            'generation' => $record['generation'],
            'owner_mode' => $ownerMode,
            'cohort' => $record['cohort'],
        ];
    }
}
