<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class LocalReferenceRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Managed mutation operations require a local reference.');
    }
}
