<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Exceptions;

use LogicException;

final class InvalidStateTransition extends LogicException
{
    public function __construct()
    {
        parent::__construct('The requested integration operation state transition is not legal.');
    }
}
