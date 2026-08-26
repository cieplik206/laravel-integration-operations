<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;

/** @api */
interface PollingStrategy
{
    public function poll(PollingContext $context): PollOutcome;
}
