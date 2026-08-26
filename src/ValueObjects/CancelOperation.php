<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class CancelOperation
{
    public function __construct(
        public IntegrationScope $scope,
        public OperationId $operationId,
        public string $reasonCode,
        public OperationActor $actor = new OperationActor('application'),
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Cancellation reason code is invalid.');
        }
    }
}
