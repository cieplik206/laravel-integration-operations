<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\Contracts\PayloadCipher;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;

/** @api */
final readonly class PayloadReencrypter
{
    public function __construct(private PayloadCipher $cipher) {}

    public function reencrypt(EncryptedEnvelope $envelope): EncryptedEnvelope
    {
        return $this->cipher->encrypt($this->cipher->decrypt($envelope));
    }
}
