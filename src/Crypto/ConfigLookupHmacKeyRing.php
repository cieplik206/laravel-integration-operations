<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use SensitiveParameter;
use SensitiveParameterValue;

/** @api */
final class ConfigLookupHmacKeyRing implements LookupHmacKeyRing
{
    /** @var array<int, SensitiveParameterValue> */
    private array $keys;

    /** @param array<int|string, mixed> $configuredKeys */
    public function __construct(
        private readonly int $activeVersion,
        #[SensitiveParameter]
        array $configuredKeys,
    ) {
        if ($activeVersion < 1) {
            throw new InvalidArgumentException('Active HMAC key version must be positive.');
        }

        $keys = [];

        foreach ($configuredKeys as $version => $configuredKey) {
            $normalizedVersion = filter_var($version, FILTER_VALIDATE_INT);

            if (! is_int($normalizedVersion) || $normalizedVersion < 1 || ! is_string($configuredKey)) {
                throw new InvalidArgumentException('HMAC key ring versions and values are invalid.');
            }

            $keys[$normalizedVersion] = new SensitiveParameterValue($this->decodeKey($configuredKey));
        }

        if (! array_key_exists($activeVersion, $keys)) {
            throw new InvalidArgumentException('Active HMAC key version is missing from the key ring.');
        }

        ksort($keys, SORT_NUMERIC);
        $this->keys = $keys;
    }

    public function activeVersion(): int
    {
        return $this->activeVersion;
    }

    public function readableVersions(): array
    {
        return array_keys($this->keys);
    }

    public function hmacSha256(int $version, string $message): string
    {
        $key = $this->keys[$version] ?? throw new OutOfBoundsException("Unknown HMAC key version {$version}.");
        $value = $key->getValue();

        if (! is_string($value)) {
            throw new LogicException('HMAC key storage is corrupted.');
        }

        return hash_hmac('sha256', $message, $value);
    }

    /** @return array{active_version: int, readable_versions: list<int>} */
    public function __debugInfo(): array
    {
        return [
            'active_version' => $this->activeVersion,
            'readable_versions' => $this->readableVersions(),
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('HMAC key rings cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('HMAC key rings cannot be cloned.');
    }

    private function decodeKey(#[SensitiveParameter] string $configuredKey): string
    {
        $key = $configuredKey;

        if (str_starts_with($configuredKey, 'base64:')) {
            $decoded = base64_decode(substr($configuredKey, 7), true);

            if ($decoded === false) {
                throw new InvalidArgumentException('HMAC key contains invalid base64.');
            }

            $key = $decoded;
        }

        if (str_starts_with($configuredKey, 'hex:')) {
            $decoded = hex2bin(substr($configuredKey, 4));

            if ($decoded === false) {
                throw new InvalidArgumentException('HMAC key contains invalid hexadecimal data.');
            }

            $key = $decoded;
        }

        if (strlen($key) < 32) {
            throw new InvalidArgumentException('HMAC keys must contain at least 32 bytes of entropy.');
        }

        return $key;
    }
}
