<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Queries;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeScopedOperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;

/** @internal */
final readonly class DatabaseAuthoritativeOperationQuery implements AuthoritativeOperationQuery
{
    public function __construct(private DatabaseAuthoritativeScopedOperationQueryFactory $factory) {}

    public function within(IntegrationScope|IntegrationScopeSet $scopes): AuthoritativeScopedOperationQuery
    {
        $allowedScopes = $scopes instanceof IntegrationScope
            ? IntegrationScopeSet::from([$scopes])
            : $scopes;

        return $this->factory->make($allowedScopes);
    }
}
