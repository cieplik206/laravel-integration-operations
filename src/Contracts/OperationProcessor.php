<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

/** @internal */
interface OperationProcessor
{
    public function process(OperationId $operationId): void;
}
