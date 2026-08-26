<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\ManualOperationResolver;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\ResolveManualOperation;
use LogicException;

final class RejectingManualOperationResolver implements ManualOperationResolver
{
    public function resolve(ResolveManualOperation $command): OperationReceipt
    {
        throw new LogicException('The manual resolver must not be called by this test.');
    }
}
