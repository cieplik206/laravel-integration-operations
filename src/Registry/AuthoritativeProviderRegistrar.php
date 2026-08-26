<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use InvalidArgumentException;

/** @api */
final readonly class AuthoritativeProviderRegistrar
{
    public function __construct(private AuthoritativeDefinitionRegistry $registry) {}

    /** @param class-string $provider */
    public function registerProvider(string $provider): void
    {
        if (! is_a($provider, AuthoritativeOperationDefinitionProvider::class, true)) {
            throw new InvalidArgumentException("Authoritative definition provider '{$provider}' does not implement the provider contract.");
        }

        $this->registry->register($provider);
    }
}
