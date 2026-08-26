<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

/** @api */
enum ProbeOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
