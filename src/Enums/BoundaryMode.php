<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum BoundaryMode: string
{
    case Forbidden = 'forbidden';
    case Required = 'required';
}
