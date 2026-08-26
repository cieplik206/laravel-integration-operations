<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;

/** @api */
interface ObservationProjector
{
    /**
     * This method is invoked inside the kernel's observation transaction. It
     * must not perform HTTP, queue dispatch, or application-model reads.
     */
    public function project(
        OperationView $operation,
        PollOutcome|AuthoritativeReconciliationOutcome $observation,
        ObservationProjectionPlan $plan,
    ): void;
}
