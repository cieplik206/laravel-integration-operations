<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** @internal */
final readonly class LeaseRecoveryCursor
{
    public string $leaseExpiresAt;

    public function __construct(
        public IntegrationScope $scope,
        string $leaseExpiresAt,
        public OperationId $operationId,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/D', $leaseExpiresAt) !== 1) {
            throw new InvalidArgumentException('Lease recovery cursor timestamp is invalid.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $leaseExpiresAt);

        if ($parsed === false || $parsed->format('Y-m-d H:i:s.uP') !== $leaseExpiresAt) {
            throw new InvalidArgumentException('Lease recovery cursor timestamp is invalid.');
        }

        $this->leaseExpiresAt = $leaseExpiresAt;
    }

    public static function fromDatabase(
        IntegrationScope $scope,
        string $leaseExpiresAt,
        OperationId $operationId,
    ): self {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?[+-]\d{2}(?::?\d{2})?$/D', $leaseExpiresAt) !== 1) {
            throw new InvalidArgumentException('Persisted lease recovery cursor timestamp is invalid.');
        }

        $parsed = new DateTimeImmutable($leaseExpiresAt);

        return new self(
            $scope,
            $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            $operationId,
        );
    }

    /** @return array{version: 1, provider: string, connection: string, lease_expires_at: string, operation_id: string} */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'provider' => $this->scope->provider->value,
            'connection' => $this->scope->connection->value,
            'lease_expires_at' => $this->leaseExpiresAt,
            'operation_id' => $this->operationId->value,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $expectedKeys = ['connection', 'lease_expires_at', 'operation_id', 'provider', 'version'];
        $actualKeys = array_keys($payload);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['provider'] ?? null)
            || strlen($payload['provider']) > 64
            || ! is_string($payload['connection'] ?? null)
            || strlen($payload['connection']) > 128
            || ! is_string($payload['lease_expires_at'] ?? null)
            || strlen($payload['lease_expires_at']) !== 32
            || ! is_string($payload['operation_id'] ?? null)
            || strlen($payload['operation_id']) !== 26) {
            throw new InvalidArgumentException('Lease recovery cursor payload is invalid.');
        }

        return new self(
            IntegrationScope::of($payload['provider'], $payload['connection']),
            $payload['lease_expires_at'],
            new OperationId($payload['operation_id']),
        );
    }
}
