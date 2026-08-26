<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @internal */
final readonly class LeaseRecoveryIncident
{
    public function __construct(
        public OperationId $operationId,
        public IntegrationScope $scope,
        public string $safeCode,
        public bool $quarantined,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $safeCode) !== 1) {
            throw new InvalidArgumentException('Lease recovery incident code is invalid.');
        }
    }
}
