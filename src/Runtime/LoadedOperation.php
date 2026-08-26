<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Registry\OperationDefinition;

/** @internal */
final readonly class LoadedOperation
{
    public function __construct(
        public LeaseClaimHandle $lease,
        public OperationDefinition $definition,
        public StoredOperationView $view,
        public int $observationNumber,
    ) {}
}
