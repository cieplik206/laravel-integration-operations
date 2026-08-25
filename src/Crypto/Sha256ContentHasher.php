<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Crypto;

use Cieplik206\IntegrationOperations\ValueObjects\Sha256Digest;

/** @api */
final class Sha256ContentHasher
{
    public function hash(string $content): Sha256Digest
    {
        return new Sha256Digest(hash('sha256', $content));
    }
}
