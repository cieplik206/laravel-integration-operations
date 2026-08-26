<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class RuntimeTransactionActive extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Durable integration runtime work cannot start inside an active transaction.');
    }
}
