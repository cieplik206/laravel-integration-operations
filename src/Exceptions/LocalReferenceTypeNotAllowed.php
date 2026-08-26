<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class LocalReferenceTypeNotAllowed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The local reference type is not allowlisted.');
    }
}
