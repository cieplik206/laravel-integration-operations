<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

/** @api */
final readonly class IntegrationScope
{
    public const Version = 1;

    public function __construct(
        public ProviderKey $provider,
        public ConnectionKey $connection,
    ) {}

    public static function of(string $provider, string $connection): self
    {
        return new self(new ProviderKey($provider), new ConnectionKey($connection));
    }

    public function provider(): ProviderKey
    {
        return $this->provider;
    }

    public function connectionKey(): ConnectionKey
    {
        return $this->connection;
    }

    /** @return array{version: int, provider: string, connection: string} */
    public function toArray(): array
    {
        return [
            'version' => self::Version,
            'provider' => $this->provider->value,
            'connection' => $this->connection->value,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $expectedKeys = ['version', 'provider', 'connection'];

        if (array_diff(array_keys($data), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($data)) !== []
            || ($data['version'] ?? null) !== self::Version
            || ! is_string($data['provider'] ?? null)
            || ! is_string($data['connection'] ?? null)) {
            throw new \InvalidArgumentException('Integration scope envelope is invalid.');
        }

        return self::of($data['provider'], $data['connection']);
    }

    public function equals(self $other): bool
    {
        return $this->provider->equals($other->provider)
            && $this->connection->equals($other->connection);
    }
}
