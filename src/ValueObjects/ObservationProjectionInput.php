<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Contracts\OperationView;

/** @api */
final readonly class ObservationProjectionInput
{
    public function __construct(
        public OperationView $operation,
        public PollOutcome|AuthoritativeReconciliationOutcome $observation,
    ) {}
}
