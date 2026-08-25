<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/**
 * Read-only execution view supplied by the kernel to a trusted provider.
 *
 * @api
 */
interface OperationExecution extends OperationView
{
    public function effectBoundary(): EffectBoundary;
}
