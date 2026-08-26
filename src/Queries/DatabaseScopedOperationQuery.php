<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Queries;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\ScopedOperationQuery;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshotBatch;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Cieplik206\IntegrationOperations\ValueObjects\Sha256Digest;
use Illuminate\Container\Container;
use Illuminate\Database\Query\Builder;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseScopedOperationQuery implements ScopedOperationQuery
{
    public function __construct(
        private IntegrationScopeSet $allowedScopes,
        private KernelDatabase $database,
        private BoundPayloadEnvelopeCodec $envelopes,
        private IntegrationContextConstraints $contextConstraints,
        private DefinitionRegistry $definitions,
        private Container $container,
    ) {}

    public function find(OperationId $operationId): ?OperationSnapshot
    {
        return $this->findMany([$operationId])->snapshots()[0] ?? null;
    }

    public function findMany(iterable $operationIds): OperationSnapshotBatch
    {
        $validated = new OperationSnapshotBatch($this->allowedScopes, $operationIds, []);
        $requestedOperationIds = $validated->missingOperationIds();

        if ($requestedOperationIds === []) {
            return $validated;
        }

        $rows = $this->query($requestedOperationIds)->get();
        $snapshots = [];

        foreach ($rows as $row) {
            $snapshots[] = $this->snapshot($row);
        }

        return new OperationSnapshotBatch($this->allowedScopes, $requestedOperationIds, $snapshots);
    }

    /** @param list<OperationId> $operationIds */
    private function query(array $operationIds): Builder
    {
        return $this->database->connection()
            ->table('integration_operations as operation')
            ->leftJoin('integration_operation_payloads as payload', function ($join): void {
                $join->on('payload.operation_id', '=', 'operation.id')
                    ->on('payload.payload_revision', '=', 'operation.current_payload_revision');
            })
            ->leftJoin('integration_operation_results as result', 'result.operation_id', '=', 'operation.id')
            ->select([
                'operation.id',
                'operation.provider',
                'operation.connection_key',
                'operation.operation_type',
                'operation.handler_version',
                'operation.status',
                'operation.last_safe_failure_code',
                'operation.last_safe_failure_summary',
                'operation.current_payload_revision',
                'payload.context_key_version',
                'payload.context_cipher',
                'payload.context_ciphertext',
                'payload.context_ciphertext_sha256',
                'payload.context_schema_version',
                'result.operation_id as result_operation_id',
                'result.result_type',
                'result.result_schema_version',
                'result.result_key_version',
                'result.result_cipher',
                'result.result_ciphertext',
                'result.result_ciphertext_sha256',
            ])
            ->whereIn('operation.id', array_map(
                static fn (OperationId $operationId): string => $operationId->value,
                $operationIds,
            ))
            ->where(function (Builder $query): void {
                foreach ($this->allowedScopes->scopes() as $scope) {
                    $query->orWhere(function (Builder $scopeQuery) use ($scope): void {
                        $scopeQuery
                            ->where('operation.provider', $scope->provider->value)
                            ->where('operation.connection_key', $scope->connection->value);
                    });
                }
            });
    }

    private function snapshot(stdClass $row): OperationSnapshot
    {
        if (! is_string($row->id ?? null)
            || ! is_string($row->provider ?? null)
            || ! is_string($row->connection_key ?? null)
            || ! is_string($row->operation_type ?? null)
            || ! is_int($row->handler_version ?? null)
            || ! is_string($row->status ?? null)
            || ! is_int($row->current_payload_revision ?? null)) {
            throw new OperationPersistenceFailed;
        }

        try {
            $operationId = new OperationId($row->id);
            $scope = IntegrationScope::of($row->provider, $row->connection_key);
            $operationType = new OperationType($row->operation_type);
            $status = OperationStatus::from($row->status);
            $context = $this->context($row, $operationId);
            [$availability, $result] = $this->result(
                $row,
                $operationId,
                $scope,
                $operationType,
                $status,
            );

            return new OperationSnapshot(
                operationId: $operationId,
                scope: $scope,
                operationType: $operationType,
                status: $status,
                resultAvailability: $availability,
                result: $result['decoded'],
                context: $context,
                safeFailure: $this->safeFailure($row),
                encodedResult: $result['encoded'],
            );
        } catch (OperationPersistenceFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }
    }

    private function context(stdClass $row, OperationId $operationId): IntegrationContext
    {
        if (! is_int($row->context_key_version ?? null)
            || ! is_string($row->context_cipher ?? null)
            || ! is_string($row->context_ciphertext ?? null)
            || ! is_string($row->context_ciphertext_sha256 ?? null)
            || ! is_int($row->context_schema_version ?? null)
            || ! is_int($row->current_payload_revision ?? null)) {
            throw new OperationPersistenceFailed;
        }

        $body = $this->envelopes->decrypt(
            new EncryptedEnvelope(
                $row->context_key_version,
                $row->context_cipher,
                $row->context_ciphertext,
                new Sha256Digest($row->context_ciphertext_sha256),
            ),
            new PayloadEnvelopeBinding(
                'context',
                $operationId,
                $row->current_payload_revision,
                $row->context_schema_version,
            ),
        )->values;

        if (array_keys($body) !== ['attributes', 'correlation_id', 'version']
            || ($body['version'] ?? null) !== IntegrationContext::Version
            || (! is_string($body['correlation_id'] ?? null) && ($body['correlation_id'] ?? null) !== null)
            || ! is_array($body['attributes'] ?? null)) {
            throw new OperationPersistenceFailed;
        }

        /** @var array<string, bool|int|string|null> $attributes */
        $attributes = $body['attributes'];

        return IntegrationContext::make(
            $body['correlation_id'],
            $attributes,
            $this->contextConstraints,
        );
    }

    /**
     * @return array{
     *     ResultAvailability,
     *     array{decoded: OperationResult|null, encoded: EncodedResult|null}
     * }
     */
    private function result(
        stdClass $row,
        OperationId $operationId,
        IntegrationScope $scope,
        OperationType $operationType,
        OperationStatus $status,
    ): array {
        if (! $status->disposition()->isTerminal()) {
            return [ResultAvailability::NotReady, ['decoded' => null, 'encoded' => null]];
        }

        if ($status !== OperationStatus::Succeeded) {
            return [ResultAvailability::NotApplicable, ['decoded' => null, 'encoded' => null]];
        }

        $encodedResult = $this->encodedResult($row, $operationId);

        if ($encodedResult === null) {
            throw new OperationPersistenceFailed;
        }

        $definition = $this->definition($scope, $operationType, $row->handler_version);
        $codec = $this->resultCodec($definition, $encodedResult);

        if ($codec === null) {
            return [ResultAvailability::CodecUnavailable, ['decoded' => null, 'encoded' => $encodedResult]];
        }

        try {
            return [
                ResultAvailability::Available,
                ['decoded' => $codec->decode($encodedResult), 'encoded' => $encodedResult],
            ];
        } catch (Throwable) {
            return [ResultAvailability::DecodeFailed, ['decoded' => null, 'encoded' => $encodedResult]];
        }
    }

    private function encodedResult(stdClass $row, OperationId $operationId): ?EncodedResult
    {
        if ($row->result_operation_id === null) {
            return null;
        }

        try {
            if (! is_string($row->result_operation_id)
                || ! hash_equals($operationId->value, $row->result_operation_id)
                || ! is_string($row->result_type ?? null)
                || ! is_int($row->result_schema_version ?? null)
                || ! is_int($row->result_key_version ?? null)
                || ! is_string($row->result_cipher ?? null)
                || ! is_string($row->result_ciphertext ?? null)
                || ! is_string($row->result_ciphertext_sha256 ?? null)) {
                throw new OperationPersistenceFailed;
            }

            $body = $this->envelopes->decrypt(
                new EncryptedEnvelope(
                    $row->result_key_version,
                    $row->result_cipher,
                    $row->result_ciphertext,
                    new Sha256Digest($row->result_ciphertext_sha256),
                ),
                new PayloadEnvelopeBinding('result', $operationId, 1, $row->result_schema_version),
            );
            $encoded = EncodedResult::fromArray($body->values);

            if (! hash_equals($row->result_type, $encoded->resultType)
                || $row->result_schema_version !== $encoded->schemaVersion) {
                throw new OperationPersistenceFailed;
            }

            return $encoded;
        } catch (OperationPersistenceFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            return null;
        }
    }

    private function definition(
        IntegrationScope $scope,
        OperationType $operationType,
        int $handlerVersion,
    ): ?OperationDefinition {
        if (! $this->definitions->isFrozen()) {
            return null;
        }

        return $this->definitions->find($scope->provider, $operationType, $handlerVersion);
    }

    private function resultCodec(
        ?OperationDefinition $definition,
        EncodedResult $encodedResult,
    ): ?OperationResultCodec {
        if ($definition?->resultCodec === null
            || $definition->versions->resultSchema !== $encodedResult->schemaVersion) {
            return null;
        }

        try {
            $resolved = $this->definitions->resolveTrustedService($definition->resultCodec, $this->container);

            if (! $resolved instanceof OperationResultCodec
                || $resolved::resultType() !== $encodedResult->resultType
                || $resolved::schemaVersion() !== $encodedResult->schemaVersion) {
                return null;
            }

            return $resolved;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeFailure(stdClass $row): ?SafeOperationFailure
    {
        $code = $row->last_safe_failure_code ?? null;
        $summary = $row->last_safe_failure_summary ?? null;

        if ($code === null && $summary === null) {
            return null;
        }

        if (! is_string($code) || ! is_string($summary)) {
            throw new OperationPersistenceFailed;
        }

        return new SafeOperationFailure($code, $summary);
    }
}
