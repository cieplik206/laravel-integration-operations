<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** @internal */
final readonly class ConfiguredIntegrationScopes
{
    public function __construct(private Repository $config) {}

    /** @return list<IntegrationScope> */
    public function all(): array
    {
        $configured = $this->config->get('integration-operations.scheduler.scopes', []);

        if (! is_array($configured) || ! array_is_list($configured)) {
            throw new InvalidArgumentException('Integration operation scheduler scopes are invalid.');
        }

        $scopes = [];
        $scopeKeys = [];

        foreach ($configured as $scope) {
            if (! is_array($scope)
                || array_keys($scope) !== ['provider', 'connection']
                || ! is_string($scope['provider'] ?? null)
                || ! is_string($scope['connection'] ?? null)) {
                throw new InvalidArgumentException('Integration operation scheduler scope is invalid.');
            }

            $integrationScope = IntegrationScope::of($scope['provider'], $scope['connection']);
            $scopeKey = "{$integrationScope->provider->value}\0{$integrationScope->connection->value}";

            if (isset($scopeKeys[$scopeKey])) {
                continue;
            }

            $scopeKeys[$scopeKey] = true;
            $scopes[] = $integrationScope;
        }

        return $scopes;
    }
}
