<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class OperationControlConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The integration operation cannot be changed in its current state.');
    }
}
