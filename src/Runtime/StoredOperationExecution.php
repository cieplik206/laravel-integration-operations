<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use LogicException;

/** @internal */
final readonly class StoredOperationExecution implements OperationExecution
{
    public function __construct(
        private StoredOperationView $view,
        private EffectBoundary $boundary,
    ) {}

    public function operationId(): OperationId
    {
        return $this->view->operationId();
    }

    public function scope(): IntegrationScope
    {
        return $this->view->scope();
    }

    public function operationType(): OperationType
    {
        return $this->view->operationType();
    }

    public function context(): IntegrationContext
    {
        return $this->view->context();
    }

    public function payload(): CanonicalObject
    {
        return $this->view->payload();
    }

    public function effectBoundary(): EffectBoundary
    {
        return $this->boundary;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Stored operation executions cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Stored operation executions cannot be cloned.');
    }
}
