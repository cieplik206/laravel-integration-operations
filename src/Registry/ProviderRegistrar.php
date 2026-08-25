<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use InvalidArgumentException;

/** @api */
final readonly class ProviderRegistrar
{
    public function __construct(private DefinitionRegistry $registry) {}

    /** @param class-string $provider */
    public function registerProvider(string $provider): void
    {
        if (! is_a($provider, OperationDefinitionProvider::class, true)) {
            throw new InvalidArgumentException("Definition provider '{$provider}' does not implement the provider contract.");
        }

        $this->registry->register($provider);
    }
}
