<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;

final class RecordingAcceptanceNotifier implements DurableAcceptanceNotifier
{
    /** @var list<OperationReceipt> */
    public array $receipts = [];

    public function notify(OperationReceipt $receipt): void
    {
        $this->receipts[] = $receipt;
    }
}
