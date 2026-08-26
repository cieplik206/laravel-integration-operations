<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class OperationIntentConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The operation intent already has a different immutable command.');
    }
}
