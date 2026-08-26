<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @internal */
enum LeaseRecoveryDisposition
{
    case Recovered;
    case Quarantined;
    case Deferred;
    case Skipped;
}
