<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class CrossConnectionTransaction extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An integration operation cannot be accepted while another database connection has an active transaction.');
    }
}
