<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

final class WriterFenceRejected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The persisted writer fence no longer owns this remote effect.');
    }
}
