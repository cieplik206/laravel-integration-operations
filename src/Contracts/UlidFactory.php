<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

/** @api */
interface UlidFactory
{
    public function generate(): OperationId;
}
