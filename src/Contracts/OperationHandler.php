<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/**
 * Trusted provider extension point resolved only from the boot-time registry.
 *
 * @api
 */
interface OperationHandler
{
    public function execute(OperationExecution $operation): ExecutionOutcome;
}
