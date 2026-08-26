<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Contracts\PayloadCipher;
use Cieplik206\IntegrationOperations\Contracts\PayloadEncryptionKeyRing;
use Cieplik206\IntegrationOperations\Exceptions\PayloadDecryptionFailed;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use LogicException;
use SensitiveParameter;
use Throwable;

/** @api */
final readonly class LaravelPayloadCipher implements PayloadCipher
{
    public function __construct(
        private PayloadEncryptionKeyRing $keyRing,
        private Sha256ContentHasher $contentHasher,
    ) {}

    public function encrypt(#[SensitiveParameter] string $plaintext): EncryptedEnvelope
    {
        $keyVersion = $this->keyRing->activeVersion();
        $ciphertext = $this->keyRing->encrypt($keyVersion, $plaintext);

        return new EncryptedEnvelope(
            keyVersion: $keyVersion,
            cipher: $this->keyRing->cipher(),
            ciphertext: $ciphertext,
            contentDigest: $this->contentHasher->hash($ciphertext),
        );
    }

    public function decrypt(EncryptedEnvelope $envelope): string
    {
        $actualDigest = $this->contentHasher->hash($envelope->ciphertext);

        if (! hash_equals($envelope->contentDigest->hex, $actualDigest->hex)) {
            throw new PayloadDecryptionFailed;
        }

        if (! hash_equals($this->keyRing->cipher(), strtoupper($envelope->cipher))) {
            throw new PayloadDecryptionFailed;
        }

        try {
            return $this->keyRing->decrypt($envelope->keyVersion, $envelope->ciphertext);
        } catch (Throwable) {
            throw new PayloadDecryptionFailed;
        }
    }

    /** @return array{cipher: string, active_key_version: int} */
    public function __debugInfo(): array
    {
        return [
            'cipher' => $this->keyRing->cipher(),
            'active_key_version' => $this->keyRing->activeVersion(),
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Payload ciphers cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Payload ciphers cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Payload ciphers cannot be cloned.');
    }
}
