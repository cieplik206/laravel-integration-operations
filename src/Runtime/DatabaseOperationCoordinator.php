<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\CompensationOperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\LocalReferenceTypeRegistry;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationTelemetry;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\InitialOperationLane;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\Enums\OperationRelationPurpose;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OperationTelemetryEvent;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Exceptions\DurableAcceptanceNotificationFailed;
use Cieplik206\IntegrationOperations\Exceptions\LocalReferenceRequired;
use Cieplik206\IntegrationOperations\Exceptions\LocalReferenceTypeNotAllowed;
use Cieplik206\IntegrationOperations\Exceptions\ManagedMutationIdentityRejected;
use Cieplik206\IntegrationOperations\Exceptions\OperationIntentConflict;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\UnsupportedOperationDefinition;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceRejected;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceUnavailable;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\CompensationContract;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Telemetry\NullOperationTelemetry;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptCompensationOperation;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationActor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationTelemetryContext;
use Cieplik206\IntegrationOperations\ValueObjects\SupersedeFailedOperation;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseOperationCoordinator implements CompensationOperationCoordinator, OperationCoordinator
{
    private const int MaximumUniqueRaceRetries = 3;

    public function __construct(
        private KernelDatabase $database,
        private DefinitionRegistry $definitions,
        private LocalReferenceTypeRegistry $localReferences,
        private WriterFenceResolver $writerFences,
        private DatabaseWriterFenceAuthority $writerFenceAuthority,
        private LookupHmacKeyRing $hmacKeys,
        private HmacSha256 $hmac,
        private CanonicalJsonV1 $canonicalJson,
        private BoundPayloadEnvelopeCodec $envelopes,
        private UlidFactory $ulids,
        private OperationStateMachine $stateMachine,
        private DurableAcceptanceNotifier $notifier,
        private Repository $config,
        private OperationTelemetry $telemetry = new NullOperationTelemetry,
        private ?AuthoritativeDefinitionRegistry $authoritativeDefinitions = null,
        private ?Container $container = null,
        private AuthoritativeOperationStateMachine $authoritativeStateMachine = new AuthoritativeOperationStateMachine,
    ) {}

    public function accept(AcceptOperation $command): OperationReceipt
    {
        $this->database->assertNoForeignTransaction();
        $this->database->connection();
        $transactionBaseline = $this->database->transactionLevels();
        $prepared = $this->prepareWithTransactionHygiene($command, $transactionBaseline);
        $this->database->assertNoForeignTransaction();
        $connection = $this->database->connection();

        return $this->persistWithUniqueRaceRetry(
            $connection,
            fn (): OperationReceipt => $this->acceptTransaction($connection, $prepared),
        );
    }

    public function supersedeFailed(SupersedeFailedOperation $command): OperationReceipt
    {
        $this->database->assertNoForeignTransaction();
        $this->database->connection();
        $transactionBaseline = $this->database->transactionLevels();
        $prepared = $this->prepareWithTransactionHygiene($command->correctedOperation, $transactionBaseline);
        $this->database->assertNoForeignTransaction();
        $connection = $this->database->connection();

        return $this->persistWithUniqueRaceRetry(
            $connection,
            fn (): OperationReceipt => $this->supersedeTransaction(
                $connection,
                $prepared,
                $command->expectedCurrentOperationId,
                $command->actor,
            ),
        );
    }

    public function acceptCompensation(AcceptCompensationOperation $command): OperationReceipt
    {
        $this->database->assertNoForeignTransaction();
        $this->database->connection();
        $transactionBaseline = $this->database->transactionLevels();
        $prepared = $this->prepareWithTransactionHygiene($command->compensation, $transactionBaseline);
        $this->database->assertNoForeignTransaction();
        $connection = $this->database->connection();

        return $this->persistWithUniqueRaceRetry(
            $connection,
            fn (): OperationReceipt => $this->acceptCompensationTransaction(
                $connection,
                $prepared,
                $command,
            ),
        );
    }

    /** @param Closure(): OperationReceipt $transaction */
    private function persistWithUniqueRaceRetry(Connection $connection, Closure $transaction): OperationReceipt
    {

        for ($attempt = 1; $attempt <= self::MaximumUniqueRaceRetries; $attempt++) {
            try {
                $receipt = $connection->transaction(
                    $transaction,
                    attempts: 1,
                );

                $this->scheduleNotificationBestEffort($connection, $receipt);
                $this->telemetry->record(
                    OperationTelemetryEvent::Accepted,
                    new SafeOperationTelemetryContext(
                        $receipt->scope->provider,
                        $receipt->operationId,
                        $receipt->operationType,
                    ),
                );

                return $receipt;
            } catch (QueryException $failure) {
                if (! $this->isUniqueViolation($failure)) {
                    throw new OperationPersistenceFailed;
                }

                if ($attempt === self::MaximumUniqueRaceRetries) {
                    throw new OperationIntentConflict;
                }
            }
        }

        throw new OperationIntentConflict;
    }

    private function acceptTransaction(
        Connection $connection,
        PreparedAcceptance $prepared,
    ): OperationReceipt {
        $writerFence = $this->writerFenceAuthority->lockForAcceptance($connection, $prepared);
        $aliases = $this->lockIntentAliases($connection, $prepared->command, $prepared->intentDigests);
        $intentIds = $aliases->pluck('intent_id')->filter()->unique()->values();

        if ($intentIds->count() > 1) {
            throw new OperationIntentConflict;
        }

        if ($intentIds->isEmpty()) {
            return $this->createFirstGeneration($connection, $prepared, $writerFence);
        }

        $intentId = $intentIds->first();

        if (! is_string($intentId)) {
            throw new OperationIntentConflict;
        }

        $this->backfillAliasesBeforeIntentLock($connection, $prepared, new OperationId($intentId));

        return $this->acceptExistingIntent($connection, $prepared, $intentId);
    }

    private function acceptCompensationTransaction(
        Connection $connection,
        PreparedAcceptance $prepared,
        AcceptCompensationOperation $command,
    ): OperationReceipt {
        $parent = $connection->table('integration_operations as operation')
            ->join(
                'integration_operation_authoritative_states as authoritative',
                'authoritative.operation_id',
                '=',
                'operation.id',
            )
            ->where('operation.id', $command->compensatesOperationId->value)
            ->where('operation.provider', $prepared->command->scope->provider->value)
            ->where('operation.connection_key', $prepared->command->scope->connection->value)
            ->lockForUpdate()
            ->first([
                'operation.provider',
                'operation.connection_key',
                'operation.operation_type',
                'operation.handler_version',
                'operation.status',
                'operation.effect_state',
                'authoritative.result_availability',
                'authoritative.terminal_proof_kind',
            ]);

        if (! $parent instanceof stdClass) {
            throw new OperationIntentConflict;
        }

        $contract = $this->compensationContract($parent, $prepared, $command);

        if (! $this->parentOutcomeAllowsCompensation($parent, $contract)) {
            throw new OperationIntentConflict;
        }

        $existingRelation = $connection->table('integration_operation_relations')
            ->where('provider', $prepared->command->scope->provider->value)
            ->where('connection_key', $prepared->command->scope->connection->value)
            ->where('parent_operation_id', $command->compensatesOperationId->value)
            ->where('purpose', OperationRelationPurpose::Compensation->value)
            ->where('slot', $command->compensationSlot)
            ->lockForUpdate()
            ->first(['child_operation_id']);
        $receipt = $this->acceptTransaction($connection, $prepared);

        if ($existingRelation instanceof stdClass) {
            if (! is_string($existingRelation->child_operation_id ?? null)
                || ! hash_equals($existingRelation->child_operation_id, $receipt->operationId->value)) {
                throw new OperationIntentConflict;
            }

            return $receipt;
        }

        $inserted = $connection->table('integration_operation_relations')->insert([
            'id' => $this->ulids->generate()->value,
            'provider' => $prepared->command->scope->provider->value,
            'connection_key' => $prepared->command->scope->connection->value,
            'parent_operation_id' => $command->compensatesOperationId->value,
            'child_operation_id' => $receipt->operationId->value,
            'purpose' => OperationRelationPurpose::Compensation->value,
            'slot' => $command->compensationSlot,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);

        if (! $inserted) {
            throw new OperationPersistenceFailed;
        }

        return $receipt;
    }

    private function compensationContract(
        stdClass $parent,
        PreparedAcceptance $prepared,
        AcceptCompensationOperation $command,
    ): CompensationContract {
        $definitions = $this->authoritativeDefinitions;

        if (! $definitions instanceof AuthoritativeDefinitionRegistry
            || ! $definitions->isFrozen()
            || ! is_string($parent->provider ?? null)
            || ! is_string($parent->operation_type ?? null)
            || ! is_int($parent->handler_version ?? null)) {
            throw new OperationIntentConflict;
        }

        try {
            $definition = $definitions->find(
                new ProviderKey($parent->provider),
                new OperationType($parent->operation_type),
                $parent->handler_version,
            );
        } catch (InvalidArgumentException) {
            throw new OperationIntentConflict;
        }

        if (! $definition instanceof AuthoritativeOperationDefinition) {
            throw new OperationIntentConflict;
        }

        foreach ($definition->compensations as $contract) {
            if ($contract->slot === $command->compensationSlot
                && $contract->childType->equals($prepared->command->operationType)) {
                return $contract;
            }
        }

        throw new OperationIntentConflict;
    }

    private function parentOutcomeAllowsCompensation(
        stdClass $parent,
        CompensationContract $contract,
    ): bool {
        if (! is_string($parent->status ?? null)
            || ! is_string($parent->effect_state ?? null)
            || ! is_string($parent->result_availability ?? null)
            || ! is_string($parent->terminal_proof_kind ?? null)) {
            return false;
        }

        foreach ($contract->allowedTerminalOutcomes as $outcome) {
            if ($outcome->status->value === $parent->status
                && $outcome->effectState->value === $parent->effect_state
                && $outcome->resultAvailability->value === $parent->result_availability
                && in_array($parent->terminal_proof_kind, array_map(
                    static fn ($proof): string => $proof->value,
                    $outcome->proofKinds,
                ), true)) {
                return true;
            }
        }

        return false;
    }

    private function createFirstGeneration(
        Connection $connection,
        PreparedAcceptance $prepared,
        DatabaseWriterFenceSnapshot $writerFence,
    ): OperationReceipt {
        $command = $prepared->command;
        $intentId = $prepared->candidateIntentId;
        $activeIntentDigest = $this->activeDigest($prepared->intentDigests);
        $localReference = $command->intent->localReference;
        $localDigest = $this->activeDigestOrNull($prepared->localReferenceDigests);
        $now = $connection->raw('CURRENT_TIMESTAMP');

        $connection->table('integration_operation_intents')->insert([
            'id' => $intentId->value,
            'provider' => $command->scope->provider->value,
            'connection_key' => $command->scope->connection->value,
            'operation_type' => $command->operationType->value,
            'resource_type' => $command->intent->resourceType,
            'semantic_slot' => $command->intent->semanticSlot,
            'local_type' => $localReference?->type,
            'local_id_key_version' => $prepared->localReferenceEnvelope?->keyVersion,
            'local_id_cipher' => $prepared->localReferenceEnvelope?->cipher,
            'local_id_ciphertext' => $prepared->localReferenceEnvelope?->ciphertext,
            'local_id_ciphertext_sha256' => $prepared->localReferenceEnvelope?->contentDigest->hex,
            'local_reference_hmac' => $localDigest?->hex,
            'intent_key_hmac' => $activeIntentDigest->hex,
            'hmac_key_version' => $activeIntentDigest->keyVersion,
            'current_generation' => 0,
            'current_operation_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertIntentAliases(
            $connection,
            $command,
            $intentId,
            $prepared->intentDigests,
            $prepared->localReferenceDigests,
        );

        return $this->createOperationGeneration(
            $connection,
            $prepared,
            $intentId,
            generation: 1,
            intentDigest: $activeIntentDigest,
            operationId: $prepared->candidateOperationId,
            supersedes: null,
            writerFence: $writerFence,
        );
    }

    private function acceptExistingIntent(
        Connection $connection,
        PreparedAcceptance $prepared,
        string $intentId,
    ): OperationReceipt {
        $command = $prepared->command;
        $intent = $connection->table('integration_operation_intents')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('id', $intentId)
            ->lockForUpdate()
            ->first();

        if (! $intent instanceof stdClass || ! is_string($intent->current_operation_id ?? null)) {
            throw new OperationIntentConflict;
        }

        $operation = $connection->table('integration_operations')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('id', $intent->current_operation_id)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof stdClass) {
            throw new OperationIntentConflict;
        }

        $this->assertCurrentOperationMatchesIntent($intent, $operation, $command);

        $payload = $connection->table('integration_operation_payloads')
            ->where('operation_id', $operation->id)
            ->where('payload_revision', $operation->current_payload_revision)
            ->lockForUpdate()
            ->first();

        if (! $payload instanceof stdClass || ! is_int($payload->hmac_key_version ?? null)) {
            throw new OperationIntentConflict;
        }

        $connection->table('integration_operation_results')
            ->where('operation_id', $operation->id)
            ->lockForUpdate()
            ->first();

        $expectedFingerprint = $prepared->digestForVersion(
            $prepared->payloadFingerprintDigests,
            $payload->hmac_key_version,
        ) ?? throw new OperationIntentConflict;

        if (is_string($payload->payload_fingerprint_hmac ?? null)
            && hash_equals($payload->payload_fingerprint_hmac, $expectedFingerprint->hex)) {
            $this->assertAuthoritativeAcceptanceMatches($connection, $prepared, (string) $operation->id);

            return new OperationReceipt(
                new OperationId((string) $operation->id),
                $command->scope,
                $command->operationType,
                true,
            );
        }

        throw new OperationIntentConflict;
    }

    private function supersedeTransaction(
        Connection $connection,
        PreparedAcceptance $prepared,
        OperationId $expectedCurrentOperationId,
        OperationActor $actor,
    ): OperationReceipt {
        $command = $prepared->command;
        $writerFence = $this->writerFenceAuthority->lockForAcceptance($connection, $prepared);
        $aliases = $this->lockIntentAliases($connection, $command, $prepared->intentDigests);
        $intentIds = $aliases->pluck('intent_id')->filter()->unique()->values();

        if ($intentIds->count() !== 1 || ! is_string($intentId = $intentIds->first())) {
            throw new OperationIntentConflict;
        }

        $intentObjectId = new OperationId($intentId);
        $this->backfillAliasesBeforeIntentLock($connection, $prepared, $intentObjectId);
        $intent = $connection->table('integration_operation_intents')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('id', $intentId)
            ->lockForUpdate()
            ->first();

        if (! $intent instanceof stdClass
            || ($intent->current_operation_id ?? null) !== $expectedCurrentOperationId->value
            || ! is_int($intent->current_generation ?? null)) {
            throw new OperationIntentConflict;
        }

        $operation = $connection->table('integration_operations')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('id', $expectedCurrentOperationId->value)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof stdClass
            || ($operation->status ?? null) !== OperationStatus::Failed->value
            || ($operation->effect_state ?? null) !== EffectState::NotApplied->value) {
            throw new OperationIntentConflict;
        }

        $this->assertCurrentOperationMatchesIntent($intent, $operation, $command);

        $payload = $connection->table('integration_operation_payloads')
            ->where('operation_id', $expectedCurrentOperationId->value)
            ->where('payload_revision', $operation->current_payload_revision)
            ->lockForUpdate()
            ->first();

        $result = $connection->table('integration_operation_results')
            ->where('operation_id', $expectedCurrentOperationId->value)
            ->lockForUpdate()
            ->first();

        if (! $payload instanceof stdClass
            || $result !== null
            || ! is_int($payload->hmac_key_version ?? null)) {
            throw new OperationIntentConflict;
        }

        $correctedFingerprint = $prepared->digestForVersion(
            $prepared->payloadFingerprintDigests,
            $payload->hmac_key_version,
        ) ?? throw new OperationIntentConflict;

        if (! is_string($payload->payload_fingerprint_hmac ?? null)
            || hash_equals($payload->payload_fingerprint_hmac, $correctedFingerprint->hex)) {
            throw new OperationIntentConflict;
        }

        return $this->createOperationGeneration(
            $connection,
            $prepared,
            $intentObjectId,
            generation: $intent->current_generation + 1,
            intentDigest: $this->activeDigest($prepared->intentDigests),
            operationId: $prepared->candidateOperationId,
            supersedes: $expectedCurrentOperationId,
            expectedCurrent: $expectedCurrentOperationId,
            transitionReason: 'superseded_failed',
            transitionActor: $actor,
            writerFence: $writerFence,
        );
    }

    private function createOperationGeneration(
        Connection $connection,
        PreparedAcceptance $prepared,
        OperationId $intentId,
        int $generation,
        VersionedHmacDigest $intentDigest,
        OperationId $operationId,
        ?OperationId $supersedes,
        DatabaseWriterFenceSnapshot $writerFence,
        ?OperationId $expectedCurrent = null,
        string $transitionReason = 'accepted',
        ?OperationActor $transitionActor = null,
    ): OperationReceipt {
        $command = $prepared->command;
        $definition = $prepared->definition;
        $payloadFingerprint = $this->activeDigest($prepared->payloadFingerprintDigests);
        $now = $connection->raw('CURRENT_TIMESTAMP');
        $initial = $prepared->authoritativeDefinition === null
            ? $this->stateMachine->initial()
            : $this->authoritativeStateMachine->initial($prepared->authoritativeDefinition->initialLane);

        if (! $writerFence->available
            || $writerFence->generation === null
            || $writerFence->ownerMode === null) {
            throw new OperationIntentConflict;
        }

        $connection->table('integration_operations')->insert([
            'id' => $operationId->value,
            'intent_id' => $intentId->value,
            'intent_generation' => $generation,
            'supersedes_operation_id' => $supersedes?->value,
            'provider' => $command->scope->provider->value,
            'connection_key' => $command->scope->connection->value,
            'operation_type' => $command->operationType->value,
            'resource_type' => $command->intent->resourceType,
            'semantic_slot' => $command->intent->semanticSlot,
            'intent_key_hmac' => $intentDigest->hex,
            'current_payload_revision' => 1,
            'payload_schema_version' => $command->versions->payloadSchema,
            'handler_version' => $command->versions->handler,
            'result_schema_version' => $command->versions->resultSchema,
            'max_remote_writes' => $definition->maximumRemoteWrites,
            'status' => $initial->toStatus->value,
            'disposition' => $initial->toDisposition->value,
            'effect_state' => $initial->toEffectState->value,
            'row_version' => 1,
            'priority' => $command->priority,
            'attempts' => 0,
            'reconcile_attempts' => 0,
            'dispatch_attempts' => 0,
            'writer_generation' => $writerFence->generation,
            'owner_mode_at_accept' => $writerFence->ownerMode->value,
            'cohort_key_hmac' => $writerFence->cohortDigest,
            'owner_hmac_key_version' => $writerFence->cohortKeyVersion,
            'accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->persistAuthoritativeContractAndState($connection, $prepared, $operationId);

        $connection->table('integration_operation_payloads')->insert([
            'id' => $this->ulids->generate()->value,
            'operation_id' => $operationId->value,
            'payload_revision' => 1,
            ...$this->payloadColumns($prepared->payloadEnvelope),
            'payload_fingerprint_hmac' => $payloadFingerprint->hex,
            'hmac_key_version' => $payloadFingerprint->keyVersion,
            'payload_schema_version' => $command->versions->payloadSchema,
            ...$this->contextColumns($prepared->contextEnvelope),
            'context_schema_version' => IntegrationContext::Version,
            'context_lookup_hmac' => $this->activeDigest($prepared->contextDigests)->hex,
            'correlation_id_hmac' => $this->activeDigestOrNull($prepared->correlationDigests)?->hex,
            'created_by_actor' => 'application',
            'created_at' => $now,
        ]);

        $this->insertOperationLookupAliases($connection, $prepared, $operationId);
        $this->insertTransition(
            $connection,
            $operationId,
            $initial,
            1,
            null,
            $transitionReason,
            $transitionActor,
        );

        $intentUpdate = $connection->table('integration_operation_intents')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('id', $intentId->value)
            ->where('current_generation', $generation - 1);

        if ($expectedCurrent === null) {
            $intentUpdate->whereNull('current_operation_id');
        } else {
            $intentUpdate->where('current_operation_id', $expectedCurrent->value);
        }

        $updatedIntent = $intentUpdate->update([
            'current_generation' => $generation,
            'current_operation_id' => $operationId->value,
            'updated_at' => $now,
        ]);

        if ($updatedIntent !== 1) {
            throw new OperationIntentConflict;
        }

        return new OperationReceipt($operationId, $command->scope, $command->operationType, false);
    }

    private function persistAuthoritativeContractAndState(
        Connection $connection,
        PreparedAcceptance $prepared,
        OperationId $operationId,
    ): void {
        $definition = $prepared->authoritativeDefinition;

        if ($definition === null) {
            return;
        }

        $writeActivationSlot = $prepared->writeActivationSlot
            ?? throw new OperationIntentConflict;
        $now = $connection->raw('CURRENT_TIMESTAMP');

        foreach ($definition->writeActivation->writeActivationSlots as $slot => $activation) {
            $connection->table('integration_operation_write_activations')->insertOrIgnore([
                'provider' => $definition->provider->value,
                'operation_type' => $definition->operationType->value,
                'handler_version' => $definition->versions->handler,
                'activation_slot' => $slot,
                'activation' => $activation->value,
                'created_at' => $now,
            ]);

            $storedActivation = $connection->table('integration_operation_write_activations')
                ->where('provider', $definition->provider->value)
                ->where('operation_type', $definition->operationType->value)
                ->where('handler_version', $definition->versions->handler)
                ->where('activation_slot', $slot)
                ->value('activation');

            if ($storedActivation !== $activation->value) {
                throw new OperationIntentConflict;
            }
        }

        foreach ($definition->terminalOutcomes->pairs as $outcome) {
            foreach ($outcome->proofKinds as $proofKind) {
                $connection->table('integration_operation_terminal_outcomes')->insertOrIgnore([
                    'provider' => $definition->provider->value,
                    'operation_type' => $definition->operationType->value,
                    'handler_version' => $definition->versions->handler,
                    'status' => $outcome->status->value,
                    'effect_state' => $outcome->effectState->value,
                    'result_availability' => $outcome->resultAvailability->value,
                    'proof_kind' => $proofKind->value,
                    'created_at' => $now,
                ]);
            }
        }

        $polling = $definition->initialLane === InitialOperationLane::Poll
            ? $definition->polling ?? throw new OperationIntentConflict
            : null;
        $pollDeadline = $polling === null
            ? null
            : $connection->scalar(
                'SELECT CURRENT_TIMESTAMP + make_interval(secs => ?) AS deadline_at',
                [$polling->deadlineSeconds],
            );

        $connection->table('integration_operation_authoritative_states')->insert([
            'operation_id' => $operationId->value,
            'contract_version' => AuthoritativeOperationDefinition::ContractVersion,
            'initial_lane' => $definition->initialLane->value,
            'write_activation_slot' => $writeActivationSlot,
            'poll_purpose' => $polling === null ? null : PollPurpose::Preflight->value,
            'poll_attempts' => 0,
            'poll_deadline_at' => $pollDeadline,
            'next_poll_at' => $polling === null ? null : $now,
            'last_polled_at' => null,
            'result_availability' => ResultAvailability::NotReady->value,
            'terminal_proof_kind' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertAuthoritativeAcceptanceMatches(
        Connection $connection,
        PreparedAcceptance $prepared,
        string $operationId,
    ): void {
        if ($prepared->authoritativeDefinition === null) {
            return;
        }

        $state = $connection->table('integration_operation_authoritative_states')
            ->where('operation_id', $operationId)
            ->lockForUpdate()
            ->first();

        if (! $state instanceof stdClass
            || ($state->contract_version ?? null) !== AuthoritativeOperationDefinition::ContractVersion
            || ($state->initial_lane ?? null) !== $prepared->authoritativeDefinition->initialLane->value
            || ($state->write_activation_slot ?? null) !== $prepared->writeActivationSlot) {
            throw new OperationIntentConflict;
        }
    }

    /**
     * @param  list<VersionedHmacDigest>  $digests
     * @return Collection<int, stdClass>
     */
    private function lockIntentAliases(Connection $connection, AcceptOperation $command, array $digests): Collection
    {
        return $connection->table('integration_operation_lookup_keys')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('lookup_type', 'intent')
            ->where(function (Builder $query) use ($digests): void {
                foreach ($digests as $index => $digest) {
                    $digestPredicate = function (Builder $alias) use ($digest): void {
                        $alias->where('key_version', $digest->keyVersion)->where('digest', $digest->hex);
                    };

                    if ($index === 0) {
                        $query->where($digestPredicate);

                        continue;
                    }

                    $query->orWhere($digestPredicate);
                }
            })
            ->orderBy('key_version')
            ->orderBy('digest')
            ->lockForUpdate()
            ->get(['intent_id']);
    }

    /**
     * @param  list<VersionedHmacDigest>  $intentDigests
     * @param  list<VersionedHmacDigest>  $localReferenceDigests
     */
    private function insertIntentAliases(
        Connection $connection,
        AcceptOperation $command,
        OperationId $intentId,
        array $intentDigests,
        array $localReferenceDigests,
    ): void {
        foreach ($intentDigests as $digest) {
            $this->insertAlias($connection, $command, 'intent', $intentId, $intentId, null, $digest);
        }

        foreach ($localReferenceDigests as $digest) {
            $this->insertAlias($connection, $command, 'local_reference', $intentId, $intentId, null, $digest);
        }
    }

    private function backfillAliasesBeforeIntentLock(
        Connection $connection,
        PreparedAcceptance $prepared,
        OperationId $intentId,
    ): void {
        $command = $prepared->command;
        $expected = [
            'intent' => $this->digestsByVersion($prepared->intentDigests),
            'local_reference' => $this->digestsByVersion($prepared->localReferenceDigests),
        ];
        $aliases = $connection->table('integration_operation_lookup_keys')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('subject_id', $intentId->value)
            ->whereIn('lookup_type', ['intent', 'local_reference'])
            ->orderBy('lookup_type')
            ->orderBy('key_version')
            ->lockForUpdate()
            ->get(['lookup_type', 'key_version', 'digest']);
        $present = [];

        foreach ($aliases as $alias) {
            if (! is_string($alias->lookup_type ?? null)
                || ! is_int($alias->key_version ?? null)
                || ! is_string($alias->digest ?? null)) {
                throw new OperationIntentConflict;
            }

            $expectedDigest = $expected[$alias->lookup_type][$alias->key_version] ?? null;

            if ($expectedDigest !== null && ! hash_equals($expectedDigest->hex, $alias->digest)) {
                throw new OperationIntentConflict;
            }

            $present["{$alias->lookup_type}|{$alias->key_version}"] = true;
        }

        foreach ($expected as $type => $digests) {
            foreach ($digests as $keyVersion => $digest) {
                if (isset($present["{$type}|{$keyVersion}"])) {
                    continue;
                }

                $this->insertAlias($connection, $command, $type, $intentId, $intentId, null, $digest);
            }
        }
    }

    private function insertOperationLookupAliases(
        Connection $connection,
        PreparedAcceptance $prepared,
        OperationId $operationId,
    ): void {
        $command = $prepared->command;

        foreach ($prepared->contextDigests as $digest) {
            $this->insertAlias($connection, $command, 'context', $operationId, null, $operationId, $digest);
        }

        foreach ($prepared->correlationDigests as $digest) {
            $this->insertAlias($connection, $command, 'correlation', $operationId, null, $operationId, $digest);
        }

        foreach ($prepared->cohortDigests as $digest) {
            $this->insertAlias($connection, $command, 'cohort', $operationId, null, $operationId, $digest);
        }
    }

    private function insertAlias(
        Connection $connection,
        AcceptOperation $command,
        string $type,
        OperationId $subject,
        ?OperationId $intentId,
        ?OperationId $operationId,
        VersionedHmacDigest $digest,
    ): void {
        $connection->table('integration_operation_lookup_keys')->insert([
            'id' => $this->ulids->generate()->value,
            'provider' => $command->scope->provider->value,
            'connection_key' => $command->scope->connection->value,
            'lookup_type' => $type,
            'subject_id' => $subject->value,
            'intent_id' => $intentId?->value,
            'operation_id' => $operationId?->value,
            'key_version' => $digest->keyVersion,
            'digest' => $digest->hex,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);
    }

    private function insertTransition(
        Connection $connection,
        OperationId $operationId,
        StateTransition $transition,
        int $resultingRowVersion,
        ?int $expectedRowVersion,
        string $reason,
        ?OperationActor $actor = null,
    ): void {
        $actorReference = $actor?->reference();
        $actorDigest = $actorReference === null
            ? null
            : $this->hmac->digest(LookupHmacDomain::Actor, $actorReference);

        $connection->table('integration_operation_transitions')->insert([
            'id' => $this->ulids->generate()->value,
            'operation_id' => $operationId->value,
            'sequence' => $resultingRowVersion,
            'from_status' => $transition->fromStatus?->value,
            'to_status' => $transition->toStatus->value,
            'from_disposition' => $transition->fromDisposition?->value,
            'to_disposition' => $transition->toDisposition->value,
            'from_effect_state' => $transition->fromEffectState?->value,
            'to_effect_state' => $transition->toEffectState->value,
            'reason_code' => $reason,
            'actor_category' => $actor->category ?? 'system',
            'actor_reference_hmac' => $actorDigest?->hex,
            'actor_hmac_key_version' => $actorDigest?->keyVersion,
            'expected_row_version' => $expectedRowVersion,
            'resulting_row_version' => $resultingRowVersion,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);
    }

    /** @return array{payload_key_version: int, payload_cipher: string, payload_ciphertext: string, payload_ciphertext_sha256: string} */
    private function payloadColumns(EncryptedEnvelope $envelope): array
    {
        return [
            'payload_key_version' => $envelope->keyVersion,
            'payload_cipher' => $envelope->cipher,
            'payload_ciphertext' => $envelope->ciphertext,
            'payload_ciphertext_sha256' => $envelope->contentDigest->hex,
        ];
    }

    /** @return array{context_key_version: int, context_cipher: string, context_ciphertext: string, context_ciphertext_sha256: string} */
    private function contextColumns(EncryptedEnvelope $envelope): array
    {
        return [
            'context_key_version' => $envelope->keyVersion,
            'context_cipher' => $envelope->cipher,
            'context_ciphertext' => $envelope->ciphertext,
            'context_ciphertext_sha256' => $envelope->contentDigest->hex,
        ];
    }

    private function localReferenceEnvelope(OperationId $intentId, ?LocalReference $reference): ?EncryptedEnvelope
    {
        if ($reference === null) {
            return null;
        }

        return $this->envelopes->encrypt(
            new PayloadEnvelopeBinding('local_reference', $intentId, 1, 1),
            new CanonicalObject(['identifier' => $reference->identifier()]),
        );
    }

    /** @return list<VersionedHmacDigest> */
    private function localReferenceDigests(LocalReference $reference): array
    {
        $material = $this->canonicalJson->encode(new CanonicalObject([
            'domain' => 'local_reference',
            'type' => $reference->type,
            'identifier' => $reference->identifier(),
        ]));

        return $this->hmac->readableDigests(LookupHmacDomain::Intent, $material);
    }

    /** @return list<VersionedHmacDigest> */
    private function intentDigests(AcceptOperation $command): array
    {
        $localReference = $command->intent->localReference;
        $material = $this->canonicalJson->encode(new CanonicalObject([
            'provider' => $command->scope->provider->value,
            'connection' => $command->scope->connection->value,
            'operation_type' => $command->operationType->value,
            'resource_type' => $command->intent->resourceType,
            'local_type' => $localReference?->type,
            'local_id' => $localReference?->identifier(),
            'semantic_slot' => $command->intent->semanticSlot,
        ]));

        return $this->hmac->readableDigests(LookupHmacDomain::Intent, $material);
    }

    private function prepare(AcceptOperation $command): PreparedAcceptance
    {
        if (! $this->definitions->isFrozen()) {
            throw new UnsupportedOperationDefinition;
        }

        $definition = $this->definition($command);
        $authoritativeDefinition = $this->authoritativeDefinition($command, $definition);
        $writeActivationSlot = null;

        if ($authoritativeDefinition !== null) {
            [$command, $writeActivationSlot] = $this->canonicalizeAuthoritativeCommand(
                $command,
                $authoritativeDefinition,
            );
            $this->assertAuthoritativeIdentity($authoritativeDefinition, $command);
        }

        (new ManagedMutationIdentityPolicy)->assertSatisfiedBy($definition, $command->intent);
        $this->assertLocalReferenceType($command->intent->localReference);
        $writerFence = $this->writerFences->current($command->scope, $command->operationType)
            ?? throw new WriterFenceUnavailable;
        $this->assertWriterFenceAllows($definition, $writerFence);
        $payloadMaterial = $this->canonicalJson->encode($command->payload);
        $maximumPayloadBytes = $this->config->get('integration-operations.runtime.maximum_payload_bytes', 262144);

        if (! is_int($maximumPayloadBytes) || $maximumPayloadBytes < 1 || $maximumPayloadBytes > 16 * 1024 * 1024) {
            throw new InvalidArgumentException('Integration operation payload byte limit is invalid.');
        }

        if (strlen($payloadMaterial) > $maximumPayloadBytes) {
            throw new InvalidArgumentException('Integration operation payload exceeds its byte limit.');
        }

        $candidateIntentId = $this->ulids->generate();
        $candidateOperationId = $this->ulids->generate();
        $contextMaterial = $this->canonicalJson->encode(new CanonicalObject($command->context->toArray()));
        $fingerprintMaterial = $this->canonicalJson->encode(new CanonicalObject([
            'payload_schema' => $command->versions->payloadSchema,
            'handler' => $command->versions->handler,
            'result_schema' => $command->versions->resultSchema,
            'payload' => $command->payload->values,
        ]));
        $localReference = $command->intent->localReference;
        $correlationId = $command->context->correlationId;
        $cohort = $writerFence->cohort();
        $cohortDigests = $cohort === null
            ? []
            : $this->hmac->readableDigests(LookupHmacDomain::Cohort, $cohort);

        return new PreparedAcceptance(
            command: $command,
            definition: $definition,
            authoritativeDefinition: $authoritativeDefinition,
            writeActivationSlot: $writeActivationSlot,
            writerFence: $writerFence,
            candidateIntentId: $candidateIntentId,
            candidateOperationId: $candidateOperationId,
            intentDigests: $this->intentDigests($command),
            localReferenceDigests: $localReference === null ? [] : $this->localReferenceDigests($localReference),
            payloadFingerprintDigests: $this->hmac->readableDigests(LookupHmacDomain::Payload, $fingerprintMaterial),
            payloadEnvelope: $this->envelopes->encrypt(
                new PayloadEnvelopeBinding('payload', $candidateOperationId, 1, $command->versions->payloadSchema),
                $command->payload,
            ),
            contextEnvelope: $this->envelopes->encrypt(
                new PayloadEnvelopeBinding('context', $candidateOperationId, 1, IntegrationContext::Version),
                new CanonicalObject($command->context->toArray()),
            ),
            localReferenceEnvelope: $this->localReferenceEnvelope($candidateIntentId, $localReference),
            contextDigests: $this->hmac->readableDigests(LookupHmacDomain::Context, $contextMaterial),
            correlationDigests: $correlationId === null
                ? []
                : $this->hmac->readableDigests(LookupHmacDomain::Correlation, $correlationId),
            cohortDigests: $cohortDigests,
            activeCohortDigest: $cohort === null ? null : $this->activeDigest($cohortDigests),
        );
    }

    /** @param array<string, int> $transactionBaseline */
    private function prepareWithTransactionHygiene(
        AcceptOperation $command,
        array $transactionBaseline,
    ): PreparedAcceptance {
        try {
            $prepared = $this->prepare($command);
        } catch (Throwable $failure) {
            $levelsWereExact = $this->transactionLevelsMatch($transactionBaseline);
            $this->restorePrepareTransactionBaseline($transactionBaseline);

            if ($levelsWereExact
                && ($failure instanceof UnsupportedOperationDefinition
                || $failure instanceof LocalReferenceRequired
                || $failure instanceof LocalReferenceTypeNotAllowed
                || $failure instanceof ManagedMutationIdentityRejected
                || $failure instanceof WriterFenceUnavailable
                || $failure instanceof WriterFenceRejected)) {
                if ($failure instanceof WriterFenceUnavailable || $failure instanceof WriterFenceRejected) {
                    $this->telemetry->record(
                        OperationTelemetryEvent::FenceDenied,
                        new SafeOperationTelemetryContext(
                            $command->scope->provider,
                            operationType: $command->operationType,
                            reasonCode: $failure instanceof WriterFenceRejected
                                ? 'writer_fence_rejected'
                                : 'writer_fence_unavailable',
                        ),
                    );
                }

                throw $failure;
            }

            $this->reportBestEffort(new OperationPersistenceFailed);

            throw new OperationPersistenceFailed;
        }

        if (! $this->transactionLevelsMatch($transactionBaseline)) {
            $this->restorePrepareTransactionBaseline($transactionBaseline);
            $this->reportBestEffort(new OperationPersistenceFailed);

            throw new OperationPersistenceFailed;
        }

        return $prepared;
    }

    /** @param array<string, int> $transactionBaseline */
    private function restorePrepareTransactionBaseline(array $transactionBaseline): void
    {
        try {
            $this->database->restoreTransactionLevels($transactionBaseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        if (! $this->transactionLevelsMatch($transactionBaseline)) {
            throw new OperationPersistenceFailed;
        }
    }

    /** @param array<string, int> $transactionBaseline */
    private function transactionLevelsMatch(array $transactionBaseline): bool
    {
        $currentLevels = $this->database->transactionLevels();
        ksort($currentLevels);
        ksort($transactionBaseline);

        return $currentLevels === $transactionBaseline;
    }

    /** @param list<VersionedHmacDigest> $digests */
    private function activeDigest(array $digests): VersionedHmacDigest
    {
        return array_find($digests, fn (VersionedHmacDigest $digest): bool => $digest->keyVersion === $this->hmacKeys->activeVersion())
            ?? throw new OperationIntentConflict;
    }

    /** @param list<VersionedHmacDigest> $digests */
    private function activeDigestOrNull(array $digests): ?VersionedHmacDigest
    {
        if ($digests === []) {
            return null;
        }

        return $this->activeDigest($digests);
    }

    /**
     * @param  list<VersionedHmacDigest>  $digests
     * @return array<int, VersionedHmacDigest>
     */
    private function digestsByVersion(array $digests): array
    {
        $byVersion = [];

        foreach ($digests as $digest) {
            $byVersion[$digest->keyVersion] = $digest;
        }

        ksort($byVersion, SORT_NUMERIC);

        return $byVersion;
    }

    private function assertWriterFenceAllows(OperationDefinition $definition, WriterFence $writerFence): void
    {
        if ($definition->maximumRemoteWrites === 1 && ! $writerFence->ownerMode->permitsRemoteWrite()) {
            throw new WriterFenceRejected;
        }

        if ($definition->maximumRemoteWrites === 0
            && $writerFence->ownerMode === OwnerMode::Off) {
            throw new WriterFenceRejected;
        }
    }

    private function assertCurrentOperationMatchesIntent(
        stdClass $intent,
        stdClass $operation,
        AcceptOperation $command,
    ): void {
        if (! is_string($intent->id ?? null)
            || ! is_int($intent->current_generation ?? null)
            || ($operation->intent_id ?? null) !== $intent->id
            || ($operation->intent_generation ?? null) !== $intent->current_generation
            || ($intent->provider ?? null) !== $command->scope->provider->value
            || ($operation->provider ?? null) !== $command->scope->provider->value
            || ($intent->connection_key ?? null) !== $command->scope->connection->value
            || ($operation->connection_key ?? null) !== $command->scope->connection->value
            || ($intent->operation_type ?? null) !== $command->operationType->value
            || ($operation->operation_type ?? null) !== $command->operationType->value
            || ($intent->resource_type ?? null) !== $command->intent->resourceType
            || ($operation->resource_type ?? null) !== $command->intent->resourceType
            || ($intent->semantic_slot ?? null) !== $command->intent->semanticSlot
            || ($operation->semantic_slot ?? null) !== $command->intent->semanticSlot
            || ($intent->local_type ?? null) !== $command->intent->localReference?->type) {
            throw new OperationIntentConflict;
        }
    }

    private function definition(AcceptOperation $command): OperationDefinition
    {
        $definition = $this->definitions->find(
            $command->scope->provider,
            $command->operationType,
            $command->versions->handler,
        );

        if ($definition === null
            || $definition->versions->payloadSchema !== $command->versions->payloadSchema
            || $definition->versions->resultSchema !== $command->versions->resultSchema) {
            throw new UnsupportedOperationDefinition;
        }

        return $definition;
    }

    private function authoritativeDefinition(
        AcceptOperation $command,
        OperationDefinition $legacyDefinition,
    ): ?AuthoritativeOperationDefinition {
        $container = $this->container ?? Container::getInstance();
        $registry = $this->authoritativeDefinitions;

        if ($registry === null && $container->bound(AuthoritativeDefinitionRegistry::class)) {
            $registry = $container->make(AuthoritativeDefinitionRegistry::class);
        }

        if ($registry === null) {
            return null;
        }

        if (! $registry->isFrozen()) {
            throw new UnsupportedOperationDefinition;
        }

        $definition = $registry->find(
            $command->scope->provider,
            $command->operationType,
            $command->versions->handler,
        );

        if ($definition === null) {
            return null;
        }

        if ($definition->versions->payloadSchema !== $command->versions->payloadSchema
            || $definition->versions->resultSchema !== $command->versions->resultSchema
            || $definition->maximumRemoteWrites !== $legacyDefinition->maximumRemoteWrites) {
            throw new UnsupportedOperationDefinition;
        }

        return $definition;
    }

    /** @return array{AcceptOperation, string} */
    private function canonicalizeAuthoritativeCommand(
        AcceptOperation $command,
        AuthoritativeOperationDefinition $definition,
    ): array {
        $container = $this->container ?? Container::getInstance();
        $resolved = ($this->authoritativeDefinitions ?? $container->make(AuthoritativeDefinitionRegistry::class))
            ->resolveTrustedService($definition->payloadCodec, $container);

        if (! $resolved instanceof OperationPayloadCodec) {
            throw new UnsupportedOperationDefinition;
        }

        $payload = $resolved->canonicalize($command->payload);
        $writeActivationSlot = $resolved->writeActivationSlot($payload);
        $definition->writeActivation->requireWriteActivationSlot($writeActivationSlot);

        return [
            new AcceptOperation(
                $command->scope,
                $command->operationType,
                $command->versions,
                $command->intent,
                $payload,
                $command->context,
                $command->priority,
            ),
            $writeActivationSlot,
        ];
    }

    private function assertAuthoritativeIdentity(
        AuthoritativeOperationDefinition $definition,
        AcceptOperation $command,
    ): void {
        if ($definition->maximumRemoteWrites === 0) {
            return;
        }

        if ($command->intent->localReference === null) {
            throw new LocalReferenceRequired;
        }

        if ($definition->managedMutationIdentity === null
            || ! $definition->managedMutationIdentity->allows($command->intent)) {
            throw new ManagedMutationIdentityRejected;
        }
    }

    private function assertLocalReferenceType(?LocalReference $reference): void
    {
        if ($reference !== null && ! $this->localReferences->allows($reference->type)) {
            throw new LocalReferenceTypeNotAllowed;
        }
    }

    private function isUniqueViolation(QueryException $failure): bool
    {
        return ($failure->errorInfo[0] ?? null) === '23505';
    }

    private function scheduleNotificationBestEffort(Connection $connection, OperationReceipt $receipt): void
    {
        try {
            $connection->afterCommit(function () use ($receipt): void {
                $this->notifyBestEffort($receipt);
            });
        } catch (OperationPersistenceFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            $this->reportBestEffort(new DurableAcceptanceNotificationFailed);
        }
    }

    private function notifyBestEffort(OperationReceipt $receipt): void
    {
        $transactionBaseline = $this->database->transactionLevels();

        if (array_filter($transactionBaseline, static fn (int $level): bool => $level !== 0) !== []) {
            $this->reportBestEffort(new DurableAcceptanceNotificationFailed);

            return;
        }

        $failed = false;

        try {
            $this->notifier->notify($receipt);
        } catch (Throwable) {
            $failed = true;
        }

        if (! $this->transactionLevelsMatch($transactionBaseline)) {
            $failed = true;
        }

        $this->restorePrepareTransactionBaseline($transactionBaseline);

        if ($failed) {
            $this->reportBestEffort(new DurableAcceptanceNotificationFailed);
        }
    }

    private function reportBestEffort(Throwable $failure): void
    {
        $transactionBaseline = $this->database->transactionLevels();

        try {
            report($failure);
        } catch (Throwable) {
        } finally {
            $this->restorePrepareTransactionBaseline($transactionBaseline);
        }
    }
}
