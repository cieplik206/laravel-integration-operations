<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\ResolveManualOperation;

/** @internal */
interface ManualOperationResolver
{
    public function resolve(ResolveManualOperation $command): OperationReceipt;
}
