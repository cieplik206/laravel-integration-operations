<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class UnsupportedOperationDefinition extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The exact persisted operation definition is not registered.');
    }
}
