<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Events;

use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use LogicException;

/** @api */
final readonly class OperationTerminalized
{
    public function __construct(
        public OperationId $eventId,
        public OperationId $operationId,
        public IntegrationScope $scope,
        public OperationStatus $status,
    ) {
        if (! $status->disposition()->isTerminal()) {
            throw new LogicException('Operation terminal event requires a terminal status.');
        }
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Operation terminal events cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Operation terminal events cannot be cloned.');
    }
}
