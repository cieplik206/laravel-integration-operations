<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

/** @internal */
final readonly class DatabaseWriterFenceAuthorityRecord
{
    public function __construct(
        public IntegrationScope $scope,
        public OperationType $operationType,
        public int $generation,
        public OwnerMode $ownerMode,
        public bool $cohortBound,
        public int $epoch,
    ) {}
}
