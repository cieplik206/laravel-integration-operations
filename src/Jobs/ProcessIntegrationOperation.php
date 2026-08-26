<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Jobs;

use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/** @internal */
final class ProcessIntegrationOperation implements ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public readonly string $operationId;

    public function __construct(string $operationId)
    {
        $this->operationId = (new OperationId($operationId))->value;
    }

    public function handle(OperationProcessor $processor): void
    {
        $processor->process(new OperationId($this->operationId));
    }
}
