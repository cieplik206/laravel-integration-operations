<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Support\OperationResultInvariant;

/**
 * Declarative successful handler outcome. Failures are thrown and classified
 * by the registered FailureClassifier.
 *
 * @api
 */
final readonly class ExecutionOutcome
{
    public function __construct(public OperationResult $result)
    {
        OperationResultInvariant::assertImmutable($result);
    }
}
