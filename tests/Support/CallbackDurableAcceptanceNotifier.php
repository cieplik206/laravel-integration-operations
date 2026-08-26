<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Closure;

final readonly class CallbackDurableAcceptanceNotifier implements DurableAcceptanceNotifier
{
    /** @param Closure(OperationReceipt): void $callback */
    public function __construct(private Closure $callback) {}

    public function notify(OperationReceipt $receipt): void
    {
        ($this->callback)($receipt);
    }
}
