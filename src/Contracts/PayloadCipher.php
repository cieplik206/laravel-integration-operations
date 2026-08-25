<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;

/** @api */
interface PayloadCipher
{
    public function encrypt(string $plaintext): EncryptedEnvelope;

    public function decrypt(EncryptedEnvelope $envelope): string;
}
