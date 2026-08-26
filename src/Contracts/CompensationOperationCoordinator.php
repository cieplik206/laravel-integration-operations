<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\AcceptCompensationOperation;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;

/** @api */
interface CompensationOperationCoordinator
{
    public function acceptCompensation(AcceptCompensationOperation $command): OperationReceipt;
}
