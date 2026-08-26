<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeProviderRegistrar;
use Cieplik206\IntegrationOperations\Registry\ProviderRegistrar;

/** @api */
final readonly class IntegrationOperations
{
    public function __construct(
        private ProviderRegistrar $registrar,
        private AuthoritativeProviderRegistrar $authoritativeRegistrar,
    ) {}

    /** @param class-string<OperationDefinitionProvider> $provider */
    public function registerProvider(string $provider): void
    {
        $this->registrar->registerProvider($provider);
    }

    /** @param class-string<AuthoritativeOperationDefinitionProvider> $provider */
    public function registerAuthoritativeProvider(string $provider): void
    {
        $this->authoritativeRegistrar->registerProvider($provider);
    }
}
