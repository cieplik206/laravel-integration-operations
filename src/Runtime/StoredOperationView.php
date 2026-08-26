<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use LogicException;

/** @internal */
final readonly class StoredOperationView implements OperationView
{
    public function __construct(
        private OperationId $operationId,
        private IntegrationScope $scope,
        private OperationType $operationType,
        private IntegrationContext $context,
        private CanonicalObject $payload,
    ) {}

    public function operationId(): OperationId
    {
        return $this->operationId;
    }

    public function scope(): IntegrationScope
    {
        return $this->scope;
    }

    public function operationType(): OperationType
    {
        return $this->operationType;
    }

    public function context(): IntegrationContext
    {
        return $this->context;
    }

    public function payload(): CanonicalObject
    {
        return $this->payload;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Stored operation views cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Stored operation views cannot be cloned.');
    }
}
