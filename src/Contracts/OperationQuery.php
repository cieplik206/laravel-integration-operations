<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;

/**
 * Creates an immutable read boundary before any operation identifier is accepted.
 *
 * @api
 */
interface OperationQuery
{
    public function within(IntegrationScope|IntegrationScopeSet $scopes): ScopedOperationQuery;
}
