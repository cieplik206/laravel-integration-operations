<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;

/** @api */
interface ReconciliationStrategy
{
    public function reconcile(ReconciliationContext $context): ReconciliationOutcome;
}
