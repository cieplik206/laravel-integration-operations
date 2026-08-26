<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Closure;

final readonly class CallbackWriterFenceResolver implements WriterFenceResolver
{
    /** @param Closure(): void $callback */
    public function __construct(
        private WriterFence $fence,
        private Closure $callback,
    ) {}

    public function current(IntegrationScope $scope, OperationType $operationType): WriterFence
    {
        ($this->callback)();

        return $this->fence;
    }
}
