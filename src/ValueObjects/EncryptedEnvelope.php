<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class EncryptedEnvelope
{
    public const Version = 1;

    public function __construct(
        public int $keyVersion,
        public string $cipher,
        public string $ciphertext,
        public Sha256Digest $contentDigest,
    ) {
        if ($keyVersion < 1) {
            throw new InvalidArgumentException('Encryption key version must be positive.');
        }

        if ($cipher === '' || $ciphertext === '') {
            throw new InvalidArgumentException('Encrypted envelope cipher and ciphertext are required.');
        }
    }

    /** @return array{version: int, key_version: int, cipher: string, ciphertext: string, content_sha256: string} */
    public function toArray(): array
    {
        return [
            'version' => self::Version,
            'key_version' => $this->keyVersion,
            'cipher' => $this->cipher,
            'ciphertext' => $this->ciphertext,
            'content_sha256' => $this->contentDigest->hex,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $expectedKeys = ['version', 'key_version', 'cipher', 'ciphertext', 'content_sha256'];

        if (array_diff(array_keys($data), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($data)) !== []
            || ($data['version'] ?? null) !== self::Version
            || ! is_int($data['key_version'] ?? null)
            || ! is_string($data['cipher'] ?? null)
            || ! is_string($data['ciphertext'] ?? null)
            || ! is_string($data['content_sha256'] ?? null)) {
            throw new InvalidArgumentException('Encrypted envelope is invalid.');
        }

        return new self(
            keyVersion: $data['key_version'],
            cipher: $data['cipher'],
            ciphertext: $data['ciphertext'],
            contentDigest: new Sha256Digest($data['content_sha256']),
        );
    }
}
