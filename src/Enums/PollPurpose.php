<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum PollPurpose: string
{
    case Preflight = 'preflight';
    case Observation = 'observation';
}
