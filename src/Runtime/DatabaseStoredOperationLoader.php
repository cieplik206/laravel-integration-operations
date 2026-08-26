<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\Sha256Digest;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseStoredOperationLoader
{
    public function __construct(
        private KernelDatabase $database,
        private DefinitionRegistry $definitions,
        private ContainerBindingInspector $bindings,
        private BoundPayloadEnvelopeCodec $envelopes,
        private IntegrationContextConstraints $contextConstraints,
    ) {}

    public function load(LeaseClaim $claim): LoadedOperation
    {
        try {
            $row = $this->row($claim);

            if (! $row instanceof stdClass) {
                throw new OperationPersistenceFailed;
            }

            $definition = $this->definition($row, $claim);
            $payload = $this->payload($row, $claim, $definition);
            $context = $this->context($row, $claim);
            $observationNumber = $claim->purpose === LeasePurpose::Reconcile
                ? $row->reconcile_attempts
                : 1;

            if (! is_int($observationNumber) || $observationNumber < 1) {
                throw new OperationPersistenceFailed;
            }

            return new LoadedOperation(
                new LeaseClaimHandle($claim),
                $definition,
                new StoredOperationView(
                    $claim->operationId,
                    $claim->scope,
                    new OperationType($row->operation_type),
                    $context,
                    $payload,
                ),
                $observationNumber,
            );
        } catch (OperationPersistenceFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }
    }

    private function row(LeaseClaim $claim): ?stdClass
    {
        $expectedStatus = $claim->purpose === LeasePurpose::Execute
            ? OperationStatus::Processing
            : OperationStatus::Reconciling;

        $row = $this->database->connection()
            ->table('integration_operations as operation')
            ->join('integration_operation_payloads as payload', function ($join): void {
                $join->on('payload.operation_id', '=', 'operation.id')
                    ->on('payload.payload_revision', '=', 'operation.current_payload_revision');
            })
            ->select([
                'operation.id',
                'operation.provider',
                'operation.connection_key',
                'operation.operation_type',
                'operation.payload_schema_version',
                'operation.handler_version',
                'operation.result_schema_version',
                'operation.max_remote_writes',
                'operation.row_version',
                'operation.reconcile_attempts',
                'operation.active_attempt_id',
                'operation.lease_owner',
                'operation.lease_token_sha256',
                'operation.current_payload_revision',
                'payload.payload_key_version',
                'payload.payload_cipher',
                'payload.payload_ciphertext',
                'payload.payload_ciphertext_sha256',
                'payload.payload_schema_version as stored_payload_schema_version',
                'payload.payload_pruned_at',
                'payload.context_key_version',
                'payload.context_cipher',
                'payload.context_ciphertext',
                'payload.context_ciphertext_sha256',
                'payload.context_schema_version',
            ])
            ->where('operation.id', $claim->operationId->value)
            ->where('operation.provider', $claim->scope->provider->value)
            ->where('operation.connection_key', $claim->scope->connection->value)
            ->where('operation.status', $expectedStatus->value)
            ->where('operation.row_version', $claim->rowVersion)
            ->where('operation.lease_owner', $claim->owner)
            ->where('operation.lease_token_sha256', hash('sha256', $claim->token()))
            ->where('operation.lease_expires_at', '>', $this->database->connection()->raw('CURRENT_TIMESTAMP'))
            ->whereNotNull('operation.active_attempt_id')
            ->whereNull('payload.payload_pruned_at')
            ->whereNotExists(function ($query) use ($claim): void {
                $query->selectRaw('1')
                    ->from('integration_operation_results')
                    ->whereColumn('integration_operation_results.operation_id', 'operation.id')
                    ->where('integration_operation_results.operation_id', $claim->operationId->value);
            })
            ->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function definition(stdClass $row, LeaseClaim $claim): OperationDefinition
    {
        if (! is_string($row->operation_type ?? null)
            || ! is_int($row->payload_schema_version ?? null)
            || ! is_int($row->handler_version ?? null)
            || ! is_int($row->result_schema_version ?? null)
            || ! is_int($row->max_remote_writes ?? null)
            || ! is_int($row->row_version ?? null)
            || ! is_string($row->active_attempt_id ?? null)
            || ! is_string($row->lease_owner ?? null)
            || ! is_string($row->lease_token_sha256 ?? null)
            || $row->row_version !== $claim->rowVersion
            || ! hash_equals($claim->owner, $row->lease_owner)
            || ! hash_equals(hash('sha256', $claim->token()), $row->lease_token_sha256)) {
            throw new OperationPersistenceFailed;
        }

        $definition = $this->definitions->find(
            $claim->scope->provider,
            new OperationType($row->operation_type),
            $row->handler_version,
        );

        if (! $definition instanceof OperationDefinition
            || ! $this->definitions->runtimeBindingsAreAvailable($definition, $this->bindings)
            || $definition->versions->payloadSchema !== $row->payload_schema_version
            || $definition->versions->resultSchema !== $row->result_schema_version
            || $definition->maximumRemoteWrites !== $row->max_remote_writes) {
            throw new OperationPersistenceFailed;
        }

        return $definition;
    }

    private function payload(stdClass $row, LeaseClaim $claim, OperationDefinition $definition): CanonicalObject
    {
        if (! is_int($row->current_payload_revision ?? null)
            || ! is_int($row->payload_key_version ?? null)
            || ! is_string($row->payload_cipher ?? null)
            || ! is_string($row->payload_ciphertext ?? null)
            || ! is_string($row->payload_ciphertext_sha256 ?? null)
            || ! is_int($row->stored_payload_schema_version ?? null)
            || $row->payload_pruned_at !== null
            || $row->stored_payload_schema_version !== $definition->versions->payloadSchema) {
            throw new OperationPersistenceFailed;
        }

        return $this->envelopes->decrypt(
            new EncryptedEnvelope(
                $row->payload_key_version,
                $row->payload_cipher,
                $row->payload_ciphertext,
                new Sha256Digest($row->payload_ciphertext_sha256),
            ),
            new PayloadEnvelopeBinding(
                'payload',
                $claim->operationId,
                $row->current_payload_revision,
                $row->stored_payload_schema_version,
            ),
        );
    }

    private function context(stdClass $row, LeaseClaim $claim): IntegrationContext
    {
        if (! is_int($row->current_payload_revision ?? null)
            || ! is_int($row->context_key_version ?? null)
            || ! is_string($row->context_cipher ?? null)
            || ! is_string($row->context_ciphertext ?? null)
            || ! is_string($row->context_ciphertext_sha256 ?? null)
            || ! is_int($row->context_schema_version ?? null)) {
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
                $claim->operationId,
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
}
