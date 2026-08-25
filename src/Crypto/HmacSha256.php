<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;

/** @api */
final readonly class HmacSha256
{
    private const Protocol = 'cieplik206.integration-operations.lookup-hmac.v1';

    public function __construct(
        private LookupHmacKeyRing $keyRing,
        private CanonicalJsonV1 $canonicalJson,
    ) {}

    public function digest(LookupHmacDomain $domain, string $material, ?int $keyVersion = null): VersionedHmacDigest
    {
        $version = $keyVersion ?? $this->keyRing->activeVersion();
        $domainSeparatedMaterial = self::Protocol."\0{$domain->value}\0{$material}";

        return new VersionedHmacDigest(
            keyVersion: $version,
            domain: $domain,
            hex: $this->keyRing->hmacSha256($version, $domainSeparatedMaterial),
        );
    }

    public function digestCanonical(LookupHmacDomain $domain, mixed $value, ?int $keyVersion = null): VersionedHmacDigest
    {
        return $this->digest($domain, $this->canonicalJson->encode($value), $keyVersion);
    }

    /** @return list<VersionedHmacDigest> */
    public function readableDigests(LookupHmacDomain $domain, string $material): array
    {
        return array_map(
            fn (int $version): VersionedHmacDigest => $this->digest($domain, $material, $version),
            $this->keyRing->readableVersions(),
        );
    }
}
