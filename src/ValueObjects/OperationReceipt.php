<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/**
 * A transaction-local acceptance handle, not proof of an outer transaction commit.
 *
 * @api
 */
final readonly class OperationReceipt
{
    public const Version = 1;

    public function __construct(
        public OperationId $operationId,
        public IntegrationScope $scope,
        public OperationType $operationType,
        public bool $wasAlreadyRegistered,
    ) {
        if (! $operationType->belongsTo($scope->provider)) {
            throw new InvalidArgumentException('Operation type does not belong to the receipt provider.');
        }
    }

    public function operationId(): OperationId
    {
        return $this->operationId;
    }

    public function provider(): ProviderKey
    {
        return $this->scope->provider;
    }

    public function connectionKey(): ConnectionKey
    {
        return $this->scope->connection;
    }

    public function operationType(): OperationType
    {
        return $this->operationType;
    }

    public function wasAlreadyRegistered(): bool
    {
        return $this->wasAlreadyRegistered;
    }

    /**
     * @return array{
     *     version: int,
     *     operation_id: string,
     *     scope: array{version: int, provider: string, connection: string},
     *     operation_type: string,
     *     was_already_registered: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => self::Version,
            'operation_id' => $this->operationId->value,
            'scope' => $this->scope->toArray(),
            'operation_type' => $this->operationType->value,
            'was_already_registered' => $this->wasAlreadyRegistered,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $expectedKeys = ['version', 'operation_id', 'scope', 'operation_type', 'was_already_registered'];
        $scope = $data['scope'] ?? null;

        if (array_diff(array_keys($data), $expectedKeys) !== []
            || array_diff($expectedKeys, array_keys($data)) !== []
            || ($data['version'] ?? null) !== self::Version
            || ! is_string($data['operation_id'] ?? null)
            || ! is_array($scope)
            || ! is_string($data['operation_type'] ?? null)
            || ! is_bool($data['was_already_registered'] ?? null)) {
            throw new InvalidArgumentException('Operation receipt envelope is invalid.');
        }

        return new self(
            operationId: new OperationId($data['operation_id']),
            scope: IntegrationScope::fromArray($scope),
            operationType: new OperationType($data['operation_type']),
            wasAlreadyRegistered: $data['was_already_registered'],
        );
    }

    public function equals(self $other): bool
    {
        return $this->operationId->equals($other->operationId)
            && $this->scope->equals($other->scope)
            && $this->operationType->equals($other->operationType)
            && $this->wasAlreadyRegistered === $other->wasAlreadyRegistered;
    }
}
