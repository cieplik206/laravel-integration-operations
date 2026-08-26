<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\DispatchBatch;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;

/** @internal */
interface PendingOperationDispatcher
{
    public function dispatch(IntegrationScope $scope, int $limit): DispatchBatch;
}
