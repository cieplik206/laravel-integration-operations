<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class AcceptCompensationOperation
{
    public function __construct(
        public OperationId $compensatesOperationId,
        public string $compensationSlot,
        public AcceptOperation $compensation,
        public OperationActor $actor = new OperationActor('application'),
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $compensationSlot) !== 1) {
            throw new InvalidArgumentException('Compensation slot is invalid.');
        }
    }
}
