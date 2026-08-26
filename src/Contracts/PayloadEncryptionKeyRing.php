<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/** @api */
interface PayloadEncryptionKeyRing
{
    public function activeVersion(): int;

    /** @return list<int> */
    public function readableVersions(): array;

    public function cipher(): string;

    public function encrypt(int $version, string $plaintext): string;

    public function decrypt(int $version, string $ciphertext): string;
}
