<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;

/** @api */
interface AuthoritativeOperationQuery
{
    public function within(IntegrationScope|IntegrationScopeSet $scopes): AuthoritativeScopedOperationQuery;
}
