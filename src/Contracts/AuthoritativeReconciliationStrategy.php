<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;

/** @api */
interface AuthoritativeReconciliationStrategy
{
    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome;
}
