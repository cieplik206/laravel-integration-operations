<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum InitialOperationLane: string
{
    case Execute = 'execute';
    case Poll = 'poll';
}
