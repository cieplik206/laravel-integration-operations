<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\CancelOperation;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\ReplacePendingOperation;
use Cieplik206\IntegrationOperations\ValueObjects\ResolveManualOperation;

/** @api */
interface OperationControl
{
    public function replacePending(ReplacePendingOperation $command): OperationReceipt;

    public function cancel(CancelOperation $command): OperationReceipt;

    public function resolveManual(ResolveManualOperation $command): OperationReceipt;
}
