<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use RuntimeException;

/** @internal */
final class DurableAcceptanceNotificationFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A durable acceptance notification failed after the operation was committed.');
    }
}
