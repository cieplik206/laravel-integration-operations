<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use LogicException;

/** @api */
final readonly class RemoteCallScope
{
    public const Version = 1;

    public function __construct(
        public IntegrationScope $integration,
        public EndpointFamily $endpointFamily,
    ) {}

    public static function of(string $provider, string $connection, string $endpointFamily): self
    {
        return new self(
            IntegrationScope::of($provider, $connection),
            new EndpointFamily($endpointFamily),
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('', [
            pack('N', self::Version),
            self::canonicalPart($this->integration->provider->value),
            self::canonicalPart($this->integration->connection->value),
            self::canonicalPart($this->endpointFamily->value),
        ]));
    }

    /** @return array{provider: string, connection: string, endpoint_family: string} */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->integration->provider->value,
            'connection' => '[REDACTED]',
            'endpoint_family' => $this->endpointFamily->value,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Remote call scopes cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Remote call scopes cannot be unserialized.');
    }

    public function __wakeup(): never
    {
        throw new LogicException('Remote call scopes cannot be unserialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Remote call scopes cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Remote call scopes cannot be cloned.');
    }

    private static function canonicalPart(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
