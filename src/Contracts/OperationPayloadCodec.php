<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;

/**
 * Pure, package-owned payload codec used to seal and re-derive the write
 * activation discriminator before any provider I/O.
 *
 * @api
 */
interface OperationPayloadCodec
{
    public static function schemaVersion(): int;

    public function canonicalize(CanonicalObject $payload): CanonicalObject;

    public function writeActivationSlot(CanonicalObject $payload): string;
}
