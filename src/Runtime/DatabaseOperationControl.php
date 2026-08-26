<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextCodec;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\ManualOperationResolver;
use Cieplik206\IntegrationOperations\Contracts\OperationControl;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Exceptions\OperationControlConflict;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\ValueObjects\CancelOperation;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationActor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Cieplik206\IntegrationOperations\ValueObjects\ReplacePendingOperation;
use Cieplik206\IntegrationOperations\ValueObjects\ResolveManualOperation;
use Cieplik206\IntegrationOperations\ValueObjects\Sha256Digest;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseOperationControl implements OperationControl
{
    public function __construct(
        private KernelDatabase $database,
        private DefinitionRegistry $definitions,
        private LookupHmacKeyRing $hmacKeys,
        private HmacSha256 $hmac,
        private CanonicalJsonV1 $canonicalJson,
        private IntegrationContextCodec $contextCodec,
        private BoundPayloadEnvelopeCodec $envelopes,
        private UlidFactory $ulids,
        private OperationStateMachine $stateMachine,
        private ManualOperationResolver $manualResolver,
        private Repository $config,
    ) {}

    public function replacePending(ReplacePendingOperation $command): OperationReceipt
    {
        $this->database->assertNoForeignTransaction();
        $connection = $this->database->connection();
        $transactionBaseline = $this->database->transactionLevels();

        try {
            $candidate = $this->prepareReplacement($connection, $command);
        } catch (OperationControlConflict $conflict) {
            $levelsWereExact = $this->transactionLevelsMatch($transactionBaseline);
            $this->restoreTransactionBaseline($transactionBaseline);

            if ($levelsWereExact) {
                throw $conflict;
            }

            $this->reportPersistenceFailure();

            throw new OperationControlConflict;
        } catch (Throwable) {
            $this->restoreTransactionBaseline($transactionBaseline);
            $this->reportPersistenceFailure();

            throw new OperationControlConflict;
        }

        if (! $this->transactionLevelsMatch($transactionBaseline)) {
            $this->restoreTransactionBaseline($transactionBaseline);
            $this->reportPersistenceFailure();

            throw new OperationControlConflict;
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $receipt = $connection->transaction(
                    fn (): OperationReceipt => $this->replacePendingTransaction($connection, $command, $candidate),
                    1,
                );

                if (! $this->transactionLevelsMatch($transactionBaseline)) {
                    $this->restoreTransactionBaseline($transactionBaseline);
                    $this->reportPersistenceFailure();

                    throw new OperationPersistenceFailed;
                }

                return $receipt;
            } catch (OperationControlConflict $conflict) {
                $levelsWereExact = $this->transactionLevelsMatch($transactionBaseline);
                $this->restoreTransactionBaseline($transactionBaseline);

                if ($levelsWereExact) {
                    throw $conflict;
                }

                $this->reportPersistenceFailure();

                throw new OperationPersistenceFailed;
            } catch (QueryException $failure) {
                $levelsWereExact = $this->transactionLevelsMatch($transactionBaseline);
                $this->restoreTransactionBaseline($transactionBaseline);

                if (! $levelsWereExact) {
                    $this->reportPersistenceFailure();

                    throw new OperationPersistenceFailed;
                }

                if ($this->isUniqueViolation($failure) && $attempt < 3) {
                    continue;
                }

                if ($this->isUniqueViolation($failure)) {
                    throw new OperationControlConflict;
                }

                $this->reportPersistenceFailure();

                throw new OperationPersistenceFailed;
            } catch (Throwable) {
                $this->restoreTransactionBaseline($transactionBaseline);
                $this->reportPersistenceFailure();

                throw new OperationPersistenceFailed;
            }
        }

        throw new OperationControlConflict;
    }

    public function cancel(CancelOperation $command): OperationReceipt
    {
        $this->database->assertNoForeignTransaction();
        $connection = $this->database->connection();
        $transactionBaseline = $this->database->transactionLevels();

        try {
            $identity = $this->operationIdentity($connection, $command->scope->provider->value, $command->scope->connection->value, $command->operationId);
            $receipt = $connection->transaction(function () use ($connection, $command, $identity): OperationReceipt {
                $this->lockCurrentIntent($connection, $identity, $command->operationId);
                $operation = $this->lockOperation($connection, $command->scope->provider->value, $command->scope->connection->value, $command->operationId);
                $result = $connection->table('integration_operation_results')
                    ->where('operation_id', $command->operationId->value)
                    ->lockForUpdate()
                    ->first();

                if (! in_array($operation->status, [OperationStatus::Pending->value, OperationStatus::RetryWait->value], true)
                    || $operation->effect_state !== EffectState::NotStarted->value
                    || $operation->request_started_at !== null
                    || $operation->lease_token_sha256 !== null
                    || $operation->active_attempt_id !== null
                    || $result !== null
                    || ! is_int($operation->row_version)
                    || ! is_int($operation->max_remote_writes)) {
                    throw new OperationControlConflict;
                }

                $fromStatus = OperationStatus::from($operation->status);
                $transition = $this->stateMachine->transition(
                    $fromStatus,
                    EffectState::NotStarted,
                    OperationStatus::Cancelled,
                    EffectState::NotStarted,
                    $operation->max_remote_writes,
                );
                $nextRowVersion = $operation->row_version + 1;
                $updated = $connection->table('integration_operations')
                    ->where('id', $command->operationId->value)
                    ->where('provider', $command->scope->provider->value)
                    ->where('connection_key', $command->scope->connection->value)
                    ->where('row_version', $operation->row_version)
                    ->whereNull('request_started_at')
                    ->update([
                        'status' => OperationStatus::Cancelled->value,
                        'disposition' => OperationStatus::Cancelled->disposition()->value,
                        'effect_state' => EffectState::NotStarted->value,
                        'row_version' => $nextRowVersion,
                        'next_attempt_at' => null,
                        'completed_at' => $connection->raw('CURRENT_TIMESTAMP'),
                        'updated_at' => $connection->raw('CURRENT_TIMESTAMP'),
                    ]);

                if ($updated !== 1) {
                    throw new OperationControlConflict;
                }

                $this->insertTransition(
                    $connection,
                    $command->operationId,
                    $transition,
                    $operation->row_version,
                    $nextRowVersion,
                    $command->reasonCode,
                    $command->actor,
                );

                return new OperationReceipt($command->operationId, $command->scope, new OperationType($operation->operation_type), false);
            }, 3);

            if (! $this->transactionLevelsMatch($transactionBaseline)) {
                $this->restoreTransactionBaseline($transactionBaseline);
                $this->reportPersistenceFailure();

                throw new OperationPersistenceFailed;
            }

            return $receipt;
        } catch (OperationControlConflict $conflict) {
            $levelsWereExact = $this->transactionLevelsMatch($transactionBaseline);
            $this->restoreTransactionBaseline($transactionBaseline);

            if ($levelsWereExact) {
                throw $conflict;
            }

            $this->reportPersistenceFailure();

            throw new OperationPersistenceFailed;
        } catch (Throwable) {
            $this->restoreTransactionBaseline($transactionBaseline);
            $this->reportPersistenceFailure();

            throw new OperationPersistenceFailed;
        }
    }

    public function resolveManual(ResolveManualOperation $command): OperationReceipt
    {
        $this->database->assertNoForeignTransaction();
        $this->database->connection();
        $transactionBaseline = $this->database->transactionLevels();

        try {
            $receipt = $this->manualResolver->resolve($command);

            if (! $this->transactionLevelsMatch($transactionBaseline)) {
                $this->restoreTransactionBaseline($transactionBaseline);
                $this->reportPersistenceFailure();

                throw new OperationPersistenceFailed;
            }

            return $receipt;
        } catch (OperationControlConflict $conflict) {
            $levelsWereExact = $this->transactionLevelsMatch($transactionBaseline);
            $this->restoreTransactionBaseline($transactionBaseline);

            if ($levelsWereExact) {
                throw $conflict;
            }

            $this->reportPersistenceFailure();

            throw new OperationPersistenceFailed;
        } catch (Throwable) {
            $this->restoreTransactionBaseline($transactionBaseline);
            $this->reportPersistenceFailure();

            throw new OperationPersistenceFailed;
        }
    }

    /** @return array{identity: stdClass, source_payload: stdClass, payload: EncryptedEnvelope, context: EncryptedEnvelope, fingerprint: VersionedHmacDigest, context_digests: list<VersionedHmacDigest>, correlation_digests: list<VersionedHmacDigest>} */
    private function prepareReplacement(Connection $connection, ReplacePendingOperation $command): array
    {
        $identity = $this->operationIdentity(
            $connection,
            $command->scope->provider->value,
            $command->scope->connection->value,
            $command->expectedCurrentOperationId,
        );

        if (! $this->definitions->isFrozen()
            || ! is_string($identity->operation_type ?? null)
            || ! is_int($identity->handler_version ?? null)
            || ! is_int($identity->payload_schema_version ?? null)
            || ! is_int($identity->result_schema_version ?? null)) {
            throw new OperationControlConflict;
        }

        $operationType = new OperationType($identity->operation_type);
        $definition = $this->definitions->find(new ProviderKey($command->scope->provider->value), $operationType, $identity->handler_version);

        if ($definition === null
            || $definition->versions->payloadSchema !== $identity->payload_schema_version
            || $definition->versions->resultSchema !== $identity->result_schema_version) {
            throw new OperationControlConflict;
        }

        $sourcePayload = $connection->table('integration_operation_payloads')
            ->where('operation_id', $command->expectedCurrentOperationId->value)
            ->where('payload_revision', $command->expectedPayloadRevision)
            ->first();

        if (! $sourcePayload instanceof stdClass
            || ! is_int($sourcePayload->payload_key_version ?? null)
            || ! is_string($sourcePayload->payload_cipher ?? null)
            || ! is_string($sourcePayload->payload_ciphertext ?? null)
            || ! is_string($sourcePayload->payload_ciphertext_sha256 ?? null)
            || ! is_string($sourcePayload->payload_fingerprint_hmac ?? null)
            || ! is_int($sourcePayload->hmac_key_version ?? null)
            || ! is_int($sourcePayload->payload_schema_version ?? null)
            || ! is_int($sourcePayload->context_key_version ?? null)
            || ! is_string($sourcePayload->context_cipher ?? null)
            || ! is_string($sourcePayload->context_ciphertext ?? null)
            || ! is_string($sourcePayload->context_ciphertext_sha256 ?? null)
            || ! is_int($sourcePayload->context_schema_version ?? null)
            || ! is_string($sourcePayload->context_lookup_hmac ?? null)
            || ($sourcePayload->correlation_id_hmac !== null && ! is_string($sourcePayload->correlation_id_hmac))) {
            throw new OperationControlConflict;
        }

        if ($sourcePayload->payload_schema_version !== $identity->payload_schema_version
            || $sourcePayload->context_schema_version !== IntegrationContext::Version) {
            throw new OperationControlConflict;
        }

        $payloadMaterial = $this->canonicalJson->encode($command->payload);
        $maximumPayloadBytes = $this->config->get('integration-operations.runtime.maximum_payload_bytes', 262144);

        if (! is_int($maximumPayloadBytes)
            || $maximumPayloadBytes < 1
            || $maximumPayloadBytes > 16 * 1024 * 1024
            || strlen($payloadMaterial) > $maximumPayloadBytes) {
            throw new OperationControlConflict;
        }

        $revision = $command->expectedPayloadRevision + 1;
        $sourceCommandPayload = $this->envelopes->decrypt(
            new EncryptedEnvelope(
                $sourcePayload->payload_key_version,
                $sourcePayload->payload_cipher,
                $sourcePayload->payload_ciphertext,
                new Sha256Digest($sourcePayload->payload_ciphertext_sha256),
            ),
            new PayloadEnvelopeBinding(
                'payload',
                $command->expectedCurrentOperationId,
                $command->expectedPayloadRevision,
                $sourcePayload->payload_schema_version,
            ),
        );
        $fingerprintMaterial = $this->canonicalJson->encode(new CanonicalObject([
            'payload_schema' => $identity->payload_schema_version,
            'handler' => $identity->handler_version,
            'result_schema' => $identity->result_schema_version,
            'payload' => $command->payload->values,
        ]));
        $context = $this->envelopes->decrypt(
            new EncryptedEnvelope(
                $sourcePayload->context_key_version,
                $sourcePayload->context_cipher,
                $sourcePayload->context_ciphertext,
                new Sha256Digest($sourcePayload->context_ciphertext_sha256),
            ),
            new PayloadEnvelopeBinding(
                'context',
                $command->expectedCurrentOperationId,
                $command->expectedPayloadRevision,
                $sourcePayload->context_schema_version,
            ),
        );
        $validatedContext = $this->contextCodec->decode($this->canonicalJson->encode($context));
        $context = new CanonicalObject($validatedContext->toArray());
        $contextMaterial = $this->canonicalJson->encode($context);
        $correlationId = $validatedContext->correlationId;
        $sourceFingerprintMaterial = $this->canonicalJson->encode(new CanonicalObject([
            'payload_schema' => $identity->payload_schema_version,
            'handler' => $identity->handler_version,
            'result_schema' => $identity->result_schema_version,
            'payload' => $sourceCommandPayload->values,
        ]));
        $sourceFingerprint = $this->hmac->digest(
            LookupHmacDomain::Payload,
            $sourceFingerprintMaterial,
            $sourcePayload->hmac_key_version,
        );
        $sourceContextDigest = $this->hmac->digest(
            LookupHmacDomain::Context,
            $contextMaterial,
            $sourcePayload->hmac_key_version,
        );
        $sourceCorrelationDigest = $correlationId === null
            ? null
            : $this->hmac->digest(
                LookupHmacDomain::Correlation,
                $correlationId,
                $sourcePayload->hmac_key_version,
            );

        if (! hash_equals($sourcePayload->payload_fingerprint_hmac, $sourceFingerprint->hex)
            || ! hash_equals($sourcePayload->context_lookup_hmac, $sourceContextDigest->hex)
            || (($sourcePayload->correlation_id_hmac === null) !== ($sourceCorrelationDigest === null))
            || ($sourceCorrelationDigest !== null
                && ! hash_equals((string) $sourcePayload->correlation_id_hmac, $sourceCorrelationDigest->hex))) {
            throw new OperationControlConflict;
        }

        return [
            'identity' => $identity,
            'source_payload' => $sourcePayload,
            'payload' => $this->envelopes->encrypt(
                new PayloadEnvelopeBinding('payload', $command->expectedCurrentOperationId, $revision, $identity->payload_schema_version),
                $command->payload,
            ),
            'context' => $this->envelopes->encrypt(
                new PayloadEnvelopeBinding('context', $command->expectedCurrentOperationId, $revision, $sourcePayload->context_schema_version),
                $context,
            ),
            'fingerprint' => $this->hmac->digest(LookupHmacDomain::Payload, $fingerprintMaterial),
            'context_digests' => $this->hmac->readableDigests(LookupHmacDomain::Context, $contextMaterial),
            'correlation_digests' => $correlationId === null
                ? []
                : $this->hmac->readableDigests(LookupHmacDomain::Correlation, $correlationId),
        ];
    }

    /** @param array{identity: stdClass, source_payload: stdClass, payload: EncryptedEnvelope, context: EncryptedEnvelope, fingerprint: VersionedHmacDigest, context_digests: list<VersionedHmacDigest>, correlation_digests: list<VersionedHmacDigest>} $candidate */
    private function replacePendingTransaction(Connection $connection, ReplacePendingOperation $command, array $candidate): OperationReceipt
    {
        $identity = $candidate['identity'];
        $this->backfillOperationAliasesBeforeIntentLock($connection, $command, $candidate);
        $this->lockCurrentIntent($connection, $identity, $command->expectedCurrentOperationId);
        $operation = $this->lockOperation(
            $connection,
            $command->scope->provider->value,
            $command->scope->connection->value,
            $command->expectedCurrentOperationId,
        );
        $payload = $connection->table('integration_operation_payloads')
            ->where('operation_id', $command->expectedCurrentOperationId->value)
            ->where('payload_revision', $operation->current_payload_revision)
            ->lockForUpdate()
            ->first();
        $result = $connection->table('integration_operation_results')
            ->where('operation_id', $command->expectedCurrentOperationId->value)
            ->lockForUpdate()
            ->first();
        $attemptExists = $connection->table('integration_operation_attempts')
            ->where('operation_id', $command->expectedCurrentOperationId->value)
            ->lockForUpdate()
            ->exists();

        if ($operation->status !== OperationStatus::Pending->value
            || $operation->effect_state !== EffectState::NotStarted->value
            || $operation->request_started_at !== null
            || $operation->lease_token_sha256 !== null
            || $operation->active_attempt_id !== null
            || $operation->attempts !== 0
            || $operation->reconcile_attempts !== 0
            || $attemptExists
            || $result !== null
            || $operation->current_payload_revision !== $command->expectedPayloadRevision
            || ! $payload instanceof stdClass
            || ! is_int($payload->hmac_key_version ?? null)
            || ! is_string($payload->payload_fingerprint_hmac ?? null)
            || ! is_int($payload->context_schema_version ?? null)
            || ! is_string($payload->context_lookup_hmac ?? null)
            || ! is_string($payload->context_ciphertext_sha256 ?? null)
            || ! is_string($candidate['source_payload']->context_ciphertext_sha256 ?? null)
            || ! hash_equals($payload->context_ciphertext_sha256, $candidate['source_payload']->context_ciphertext_sha256)
            || ! is_string($payload->payload_ciphertext_sha256 ?? null)
            || ! is_string($candidate['source_payload']->payload_ciphertext_sha256 ?? null)
            || ! hash_equals($payload->payload_ciphertext_sha256, $candidate['source_payload']->payload_ciphertext_sha256)
            || $payload->hmac_key_version !== $candidate['source_payload']->hmac_key_version
            || $payload->payload_fingerprint_hmac !== $candidate['source_payload']->payload_fingerprint_hmac
            || $payload->context_lookup_hmac !== $candidate['source_payload']->context_lookup_hmac
            || $payload->correlation_id_hmac !== $candidate['source_payload']->correlation_id_hmac
            || ! is_int($operation->row_version)
            || ! is_string($operation->operation_type)) {
            throw new OperationControlConflict;
        }

        $candidateForPersistedVersion = $this->hmac->digest(
            LookupHmacDomain::Payload,
            $this->canonicalJson->encode(new CanonicalObject([
                'payload_schema' => $operation->payload_schema_version,
                'handler' => $operation->handler_version,
                'result_schema' => $operation->result_schema_version,
                'payload' => $command->payload->values,
            ])),
            $payload->hmac_key_version,
        );

        if (hash_equals($payload->payload_fingerprint_hmac, $candidateForPersistedVersion->hex)) {
            return new OperationReceipt(
                $command->expectedCurrentOperationId,
                $command->scope,
                new OperationType($operation->operation_type),
                true,
            );
        }

        $newRevision = $command->expectedPayloadRevision + 1;
        $connection->table('integration_operation_payloads')->insert([
            'id' => $this->ulids->generate()->value,
            'operation_id' => $command->expectedCurrentOperationId->value,
            'payload_revision' => $newRevision,
            ...$this->payloadColumns($candidate['payload']),
            'payload_fingerprint_hmac' => $candidate['fingerprint']->hex,
            'hmac_key_version' => $candidate['fingerprint']->keyVersion,
            'payload_schema_version' => $operation->payload_schema_version,
            ...$this->contextColumns($candidate['context']),
            'context_schema_version' => $payload->context_schema_version,
            'context_lookup_hmac' => $this->activeDigest($candidate['context_digests'])->hex,
            'correlation_id_hmac' => $candidate['correlation_digests'] === []
                ? null
                : $this->activeDigest($candidate['correlation_digests'])->hex,
            'created_by_actor' => $command->actor->category,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);

        $nextRowVersion = $operation->row_version + 1;
        $updated = $connection->table('integration_operations')
            ->where('id', $command->expectedCurrentOperationId->value)
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('row_version', $operation->row_version)
            ->where('current_payload_revision', $command->expectedPayloadRevision)
            ->where('status', OperationStatus::Pending->value)
            ->whereNull('request_started_at')
            ->update([
                'current_payload_revision' => $newRevision,
                'row_version' => $nextRowVersion,
                'updated_at' => $connection->raw('CURRENT_TIMESTAMP'),
            ]);

        if ($updated !== 1) {
            throw new OperationControlConflict;
        }

        $sameState = new StateTransition(
            OperationStatus::Pending,
            OperationStatus::Pending->disposition(),
            EffectState::NotStarted,
            OperationStatus::Pending,
            OperationStatus::Pending->disposition(),
            EffectState::NotStarted,
        );
        $this->insertTransition(
            $connection,
            $command->expectedCurrentOperationId,
            $sameState,
            $operation->row_version,
            $nextRowVersion,
            'payload_replaced_pending',
            $command->actor,
        );

        return new OperationReceipt(
            $command->expectedCurrentOperationId,
            $command->scope,
            new OperationType($operation->operation_type),
            false,
        );
    }

    /** @param array{context_digests: list<VersionedHmacDigest>, correlation_digests: list<VersionedHmacDigest>} $candidate */
    private function backfillOperationAliasesBeforeIntentLock(
        Connection $connection,
        ReplacePendingOperation $command,
        array $candidate,
    ): void {
        $expected = [
            'context' => $this->digestsByVersion($candidate['context_digests']),
            'correlation' => $this->digestsByVersion($candidate['correlation_digests']),
        ];
        $aliases = $connection->table('integration_operation_lookup_keys')
            ->where('provider', $command->scope->provider->value)
            ->where('connection_key', $command->scope->connection->value)
            ->where('subject_id', $command->expectedCurrentOperationId->value)
            ->whereIn('lookup_type', ['context', 'correlation'])
            ->orderBy('lookup_type')
            ->orderBy('key_version')
            ->lockForUpdate()
            ->get(['lookup_type', 'key_version', 'digest', 'retired_at']);
        $present = [];

        foreach ($aliases as $alias) {
            if (! is_string($alias->lookup_type ?? null)
                || ! is_int($alias->key_version ?? null)
                || ! is_string($alias->digest ?? null)
                || ($alias->retired_at !== null && ! is_string($alias->retired_at))) {
                throw new OperationControlConflict;
            }

            $expectedDigest = $expected[$alias->lookup_type][$alias->key_version] ?? null;

            if ($alias->lookup_type === 'correlation' && $expected['correlation'] === []) {
                throw new OperationControlConflict;
            }

            if ($expectedDigest !== null
                && ($alias->retired_at !== null || ! hash_equals($expectedDigest->hex, $alias->digest))) {
                throw new OperationControlConflict;
            }

            $present["{$alias->lookup_type}|{$alias->key_version}"] = true;
        }

        foreach ($expected as $type => $digests) {
            foreach ($digests as $version => $digest) {
                if (isset($present["{$type}|{$version}"])) {
                    continue;
                }

                $this->insertOperationAlias($connection, $command, $type, $digest);
            }
        }
    }

    private function insertOperationAlias(
        Connection $connection,
        ReplacePendingOperation $command,
        string $type,
        VersionedHmacDigest $digest,
    ): void {
        $connection->table('integration_operation_lookup_keys')->insert([
            'id' => $this->ulids->generate()->value,
            'provider' => $command->scope->provider->value,
            'connection_key' => $command->scope->connection->value,
            'lookup_type' => $type,
            'subject_id' => $command->expectedCurrentOperationId->value,
            'intent_id' => null,
            'operation_id' => $command->expectedCurrentOperationId->value,
            'key_version' => $digest->keyVersion,
            'digest' => $digest->hex,
            'created_at' => $connection->raw('CURRENT_TIMESTAMP'),
        ]);
    }

    /** @param list<VersionedHmacDigest> $digests */
    private function activeDigest(array $digests): VersionedHmacDigest
    {
        return array_find(
            $digests,
            fn (VersionedHmacDigest $digest): bool => $digest->keyVersion === $this->hmacKeys->activeVersion(),
        ) ?? throw new OperationControlConflict;
    }

    /**
     * @param  list<VersionedHmacDigest>  $digests
     * @return array<int, VersionedHmacDigest>
     */
    private function digestsByVersion(array $digests): array
    {
        $versions = [];

        foreach ($digests as $digest) {
            $versions[$digest->keyVersion] = $digest;
        }

        ksort($versions, SORT_NUMERIC);

        return $versions;
    }

    private function isUniqueViolation(QueryException $failure): bool
    {
        return ($failure->errorInfo[0] ?? null) === '23505';
    }

    /** @param array<string, int> $transactionBaseline */
    private function restoreTransactionBaseline(array $transactionBaseline): void
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

    private function reportPersistenceFailure(): void
    {
        $transactionBaseline = $this->database->transactionLevels();

        try {
            report(new OperationPersistenceFailed);
        } catch (Throwable) {
        } finally {
            $this->restoreTransactionBaseline($transactionBaseline);
        }
    }

    private function operationIdentity(Connection $connection, string $provider, string $connectionKey, OperationId $operationId): stdClass
    {
        $identity = $connection->table('integration_operations')
            ->where('provider', $provider)
            ->where('connection_key', $connectionKey)
            ->where('id', $operationId->value)
            ->first([
                'id',
                'intent_id',
                'intent_generation',
                'operation_type',
                'resource_type',
                'semantic_slot',
                'handler_version',
                'payload_schema_version',
                'result_schema_version',
            ]);

        if (! $identity instanceof stdClass) {
            throw new OperationControlConflict;
        }

        return $identity;
    }

    private function lockCurrentIntent(Connection $connection, stdClass $identity, OperationId $operationId): void
    {
        if (! is_string($identity->intent_id ?? null) || ! is_int($identity->intent_generation ?? null)) {
            throw new OperationControlConflict;
        }

        $intent = $connection->table('integration_operation_intents')
            ->where('id', $identity->intent_id)
            ->lockForUpdate()
            ->first(['current_operation_id', 'current_generation']);

        if (! $intent instanceof stdClass
            || $intent->current_operation_id !== $operationId->value
            || $intent->current_generation !== $identity->intent_generation) {
            throw new OperationControlConflict;
        }
    }

    private function lockOperation(Connection $connection, string $provider, string $connectionKey, OperationId $operationId): stdClass
    {
        $operation = $connection->table('integration_operations')
            ->where('provider', $provider)
            ->where('connection_key', $connectionKey)
            ->where('id', $operationId->value)
            ->lockForUpdate()
            ->first();

        if (! $operation instanceof stdClass) {
            throw new OperationControlConflict;
        }

        return $operation;
    }

    private function insertTransition(
        Connection $connection,
        OperationId $operationId,
        StateTransition $transition,
        int $expectedRowVersion,
        int $resultingRowVersion,
        string $reasonCode,
        OperationActor $actor,
    ): void {
        $actorDigest = $actor->reference() === null
            ? null
            : $this->hmac->digest(LookupHmacDomain::Actor, $actor->reference());

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
            'reason_code' => $reasonCode,
            'actor_category' => $actor->category,
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
}
