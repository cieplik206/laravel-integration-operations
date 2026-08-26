<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Queries;

use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Contracts\ScopedOperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;

/** @internal */
final readonly class DatabaseOperationQuery implements OperationQuery
{
    public function __construct(private DatabaseScopedOperationQueryFactory $factory) {}

    public function within(IntegrationScope|IntegrationScopeSet $scopes): ScopedOperationQuery
    {
        $allowedScopes = $scopes instanceof IntegrationScope
            ? IntegrationScopeSet::from([$scopes])
            : $scopes;

        return $this->factory->make($allowedScopes);
    }
}
