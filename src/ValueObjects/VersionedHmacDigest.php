<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use InvalidArgumentException;

/** @api */
final readonly class VersionedHmacDigest
{
    public function __construct(
        public int $keyVersion,
        public LookupHmacDomain $domain,
        public string $hex,
    ) {
        if ($keyVersion < 1) {
            throw new InvalidArgumentException('HMAC key version must be positive.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1) {
            throw new InvalidArgumentException('HMAC digest must be 64 lowercase hexadecimal characters.');
        }
    }

    /** @return array{key_version: int, domain: string, digest: string} */
    public function toArray(): array
    {
        return [
            'key_version' => $this->keyVersion,
            'domain' => $this->domain->value,
            'digest' => $this->hex,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->keyVersion === $other->keyVersion
            && $this->domain === $other->domain
            && hash_equals($this->hex, $other->hex);
    }
}
