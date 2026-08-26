<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Countable;
use InvalidArgumentException;

/** @api */
final readonly class IntegrationScopeSet implements Countable
{
    public const MaximumScopes = 500;

    /** @var list<IntegrationScope> */
    private array $scopes;

    /** @var array<string, true> */
    private array $scopeKeys;

    /** @param iterable<mixed> $scopes */
    private function __construct(iterable $scopes)
    {
        $uniqueScopes = [];

        foreach ($scopes as $scope) {
            if (! $scope instanceof IntegrationScope) {
                throw new InvalidArgumentException('Integration scope set contains an invalid value.');
            }

            $scopeKey = self::scopeKey($scope);

            if (isset($uniqueScopes[$scopeKey])) {
                continue;
            }

            $uniqueScopes[$scopeKey] = $scope;

            if (count($uniqueScopes) > self::MaximumScopes) {
                throw new InvalidArgumentException('Integration scope set exceeds its maximum size.');
            }
        }

        if ($uniqueScopes === []) {
            throw new InvalidArgumentException('Integration scope set cannot be empty.');
        }

        $this->scopes = array_values($uniqueScopes);
        $this->scopeKeys = array_fill_keys(array_keys($uniqueScopes), true);
    }

    /** @param iterable<IntegrationScope> $scopes */
    public static function from(iterable $scopes): self
    {
        return new self($scopes);
    }

    /** @return list<IntegrationScope> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function contains(IntegrationScope $scope): bool
    {
        return isset($this->scopeKeys[self::scopeKey($scope)]);
    }

    public function count(): int
    {
        return count($this->scopes);
    }

    private static function scopeKey(IntegrationScope $scope): string
    {
        return "{$scope->provider->value}\0{$scope->connection->value}";
    }
}
