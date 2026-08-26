<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Contracts\PayloadEncryptionKeyRing;
use Cieplik206\IntegrationOperations\Exceptions\PayloadDecryptionFailed;
use Cieplik206\IntegrationOperations\Exceptions\PayloadEncryptionFailed;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use SensitiveParameter;
use SensitiveParameterValue;

/** @api */
final class ConfigPayloadEncryptionKeyRing implements PayloadEncryptionKeyRing
{
    /** @var array<int, SensitiveParameterValue> */
    private array $encrypters;

    /** @param array<int|string, mixed> $configuredKeys */
    public function __construct(
        private readonly int $activeVersion,
        private readonly string $configuredCipher,
        #[SensitiveParameter]
        array $configuredKeys,
    ) {
        if ($activeVersion < 1) {
            throw new InvalidArgumentException('Active payload encryption key version must be positive.');
        }

        $cipher = strtoupper($configuredCipher);

        if (! in_array($cipher, ['AES-128-GCM', 'AES-256-GCM'], true)) {
            throw new InvalidArgumentException('Payload encryption cipher must be an authenticated AES-GCM cipher.');
        }

        $encrypters = [];

        foreach ($configuredKeys as $version => $configuredKey) {
            $normalizedVersion = filter_var($version, FILTER_VALIDATE_INT);

            if (! is_int($normalizedVersion) || $normalizedVersion < 1 || ! is_string($configuredKey)) {
                throw new InvalidArgumentException('Payload encryption key ring versions and values are invalid.');
            }

            $key = $this->decodeKey($configuredKey);

            if (! Encrypter::supported($key, $cipher)) {
                throw new InvalidArgumentException('Payload encryption key length does not match the configured cipher.');
            }

            $encrypters[$normalizedVersion] = new SensitiveParameterValue(new Encrypter($key, $cipher));
        }

        if (! array_key_exists($activeVersion, $encrypters)) {
            throw new InvalidArgumentException('Active payload encryption key version is missing from the key ring.');
        }

        ksort($encrypters, SORT_NUMERIC);
        $this->encrypters = $encrypters;
    }

    public function activeVersion(): int
    {
        return $this->activeVersion;
    }

    public function readableVersions(): array
    {
        return array_keys($this->encrypters);
    }

    public function cipher(): string
    {
        return strtoupper($this->configuredCipher);
    }

    public function encrypt(int $version, #[SensitiveParameter] string $plaintext): string
    {
        try {
            return $this->encrypter($version)->encryptString($plaintext);
        } catch (EncryptException) {
            throw new PayloadEncryptionFailed;
        }
    }

    public function decrypt(int $version, string $ciphertext): string
    {
        try {
            return $this->encrypter($version)->decryptString($ciphertext);
        } catch (DecryptException) {
            throw new PayloadDecryptionFailed;
        }
    }

    /** @return array{active_version: int, readable_versions: list<int>, cipher: string} */
    public function __debugInfo(): array
    {
        return [
            'active_version' => $this->activeVersion,
            'readable_versions' => $this->readableVersions(),
            'cipher' => $this->cipher(),
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Payload encryption key rings cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Payload encryption key rings cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Payload encryption key rings cannot be cloned.');
    }

    private function encrypter(int $version): Encrypter
    {
        $sensitiveEncrypter = $this->encrypters[$version]
            ?? throw new OutOfBoundsException("Unknown payload encryption key version {$version}.");
        $encrypter = $sensitiveEncrypter->getValue();

        if (! $encrypter instanceof Encrypter) {
            throw new LogicException('Payload encryption key storage is corrupted.');
        }

        return $encrypter;
    }

    private function decodeKey(#[SensitiveParameter] string $configuredKey): string
    {
        if (! str_starts_with($configuredKey, 'base64:')) {
            throw new InvalidArgumentException('Payload encryption keys must use the base64: format.');
        }

        $decoded = base64_decode(substr($configuredKey, 7), true);

        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('Payload encryption key contains invalid base64.');
        }

        return $decoded;
    }
}
