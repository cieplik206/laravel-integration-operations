<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class ManagedMutationIdentityRejected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The managed mutation identity is not declared by its operation definition.');
    }
}
