<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;

/** @api */
interface WriterFenceResolver
{
    public function current(IntegrationScope $scope, OperationType $operationType): ?WriterFence;
}
