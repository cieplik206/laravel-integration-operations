<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;

/** @api */
interface RetryPolicy
{
    public function decide(OperationView $operation, FailureClassification $failure): RetryInstruction;
}
