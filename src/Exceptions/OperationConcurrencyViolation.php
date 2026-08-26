<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class OperationConcurrencyViolation extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The integration operation changed concurrently.');
    }
}
