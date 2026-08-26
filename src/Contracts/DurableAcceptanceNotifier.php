<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;

/** @internal */
interface DurableAcceptanceNotifier
{
    public function notify(OperationReceipt $receipt): void;
}
