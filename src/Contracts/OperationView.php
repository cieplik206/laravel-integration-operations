<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

/**
 * Read-only provider view with no lifecycle mutation or effect capability.
 *
 * @api
 */
interface OperationView
{
    public function operationId(): OperationId;

    public function scope(): IntegrationScope;

    public function operationType(): OperationType;

    public function context(): IntegrationContext;

    public function payload(): CanonicalObject;
}
