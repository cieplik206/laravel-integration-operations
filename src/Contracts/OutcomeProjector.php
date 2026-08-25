<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/** @api */
interface OutcomeProjector
{
    /**
     * This method is invoked inside the kernel's terminal transaction. It must
     * not perform HTTP, queue dispatch, or application-model reads.
     */
    public function project(OperationView $operation, ExecutionOutcome $outcome): void;
}
