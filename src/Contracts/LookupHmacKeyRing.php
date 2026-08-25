<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/** @api */
interface LookupHmacKeyRing
{
    public function activeVersion(): int;

    /** @return list<int> */
    public function readableVersions(): array;

    public function hmacSha256(int $version, string $message): string;
}
