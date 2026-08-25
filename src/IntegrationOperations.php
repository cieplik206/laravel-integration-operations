<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations;

use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Registry\ProviderRegistrar;

/** @api */
final readonly class IntegrationOperations
{
    public function __construct(private ProviderRegistrar $registrar) {}

    /** @param class-string<OperationDefinitionProvider> $provider */
    public function registerProvider(string $provider): void
    {
        $this->registrar->registerProvider($provider);
    }
}
