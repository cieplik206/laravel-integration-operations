<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/** @api */
interface ReconciliationContext extends OperationView
{
    public function observationNumber(): int;
}
