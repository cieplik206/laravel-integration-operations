<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\SupersedeFailedOperation;

/** @api */
interface OperationCoordinator
{
    public function accept(AcceptOperation $command): OperationReceipt;

    public function supersedeFailed(SupersedeFailedOperation $command): OperationReceipt;
}
