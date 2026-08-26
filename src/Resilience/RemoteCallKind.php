<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

/** @api */
enum RemoteCallKind: string
{
    case Read = 'read';
    case Mutation = 'mutation';
}
