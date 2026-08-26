<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;

/** @api */
interface AuthoritativeRetryPolicy
{
    public function decide(OperationView $operation, ClassifiedFailure $failure): RetryInstruction;
}
