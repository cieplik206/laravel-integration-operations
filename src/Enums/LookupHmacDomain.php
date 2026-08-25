<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum LookupHmacDomain: string
{
    case Intent = 'intent';
    case Payload = 'payload';
    case Context = 'context';
    case Correlation = 'correlation';
    case Cohort = 'cohort';
}
