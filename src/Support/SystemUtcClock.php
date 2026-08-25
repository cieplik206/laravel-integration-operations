<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Support;

use Cieplik206\IntegrationOperations\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class SystemUtcClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
