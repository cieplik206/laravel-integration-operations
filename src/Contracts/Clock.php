<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use DateTimeImmutable;

/** @api */
interface Clock
{
    public function now(): DateTimeImmutable;
}
