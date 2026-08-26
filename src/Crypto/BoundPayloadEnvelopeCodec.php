<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Contracts\PayloadCipher;
use Cieplik206\IntegrationOperations\Exceptions\PayloadDecryptionFailed;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;
use SensitiveParameter;
use Throwable;

/** @api */
final readonly class BoundPayloadEnvelopeCodec
{
    private const string Protocol = 'cieplik206.integration-operations.encrypted-envelope.v1';

    public function __construct(
        private PayloadCipher $cipher,
        private CanonicalJsonV1 $canonicalJson,
    ) {}

    public function encrypt(PayloadEnvelopeBinding $binding, #[SensitiveParameter] CanonicalObject $body): EncryptedEnvelope
    {
        return $this->cipher->encrypt($this->canonicalJson->encode(new CanonicalObject([
            'protocol' => self::Protocol,
            'kind' => $binding->kind,
            'operation_id' => $binding->operationId->value,
            'revision' => $binding->revision,
            'schema_version' => $binding->schemaVersion,
            'body' => $body->values,
        ])));
    }

    public function decrypt(EncryptedEnvelope $envelope, PayloadEnvelopeBinding $binding): CanonicalObject
    {
        try {
            $decoded = json_decode($this->cipher->decrypt($envelope), true, 512, JSON_THROW_ON_ERROR);
            $this->assertEnvelope($decoded, $binding);

            /** @var array<string, mixed> $body */
            $body = $decoded['body'];

            return new CanonicalObject($body);
        } catch (Throwable) {
            throw new PayloadDecryptionFailed;
        }
    }

    private function assertEnvelope(mixed $decoded, PayloadEnvelopeBinding $binding): void
    {
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new PayloadDecryptionFailed;
        }

        $expectedKeys = ['body', 'kind', 'operation_id', 'protocol', 'revision', 'schema_version'];

        if (array_keys($decoded) !== $expectedKeys
            || ($decoded['protocol'] ?? null) !== self::Protocol
            || ($decoded['kind'] ?? null) !== $binding->kind
            || ($decoded['operation_id'] ?? null) !== $binding->operationId->value
            || ($decoded['revision'] ?? null) !== $binding->revision
            || ($decoded['schema_version'] ?? null) !== $binding->schemaVersion
            || ! is_array($decoded['body'] ?? null)
            || (array_is_list($decoded['body']) && $decoded['body'] !== [])) {
            throw new PayloadDecryptionFailed;
        }
    }
}
