<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Throwable;

/** @api */
interface FailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): FailureClassification;
}
