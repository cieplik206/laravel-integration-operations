<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;

/** @api */
interface OperationResultCodec
{
    public static function resultType(): string;

    public static function schemaVersion(): int;

    public function encode(OperationResult $result): EncodedResult;

    public function decode(EncodedResult $result): OperationResult;
}
