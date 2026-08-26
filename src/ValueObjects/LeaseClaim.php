<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/** @internal */
final readonly class LeaseClaim
{
    private SensitiveParameterValue $sensitiveToken;

    public function __construct(
        public OperationId $operationId,
        public IntegrationScope $scope,
        public LeasePurpose $purpose,
        public string $owner,
        #[SensitiveParameter]
        string $token,
        public int $rowVersion,
    ) {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $owner) !== 1) {
            throw new InvalidArgumentException('Lease owner is invalid.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || $rowVersion < 1) {
            throw new InvalidArgumentException('Lease token or row version is invalid.');
        }

        $this->sensitiveToken = new SensitiveParameterValue($token);
    }

    public function token(): string
    {
        $token = $this->sensitiveToken->getValue();

        if (! is_string($token)) {
            throw new LogicException('Lease claim token storage is corrupted.');
        }

        return $token;
    }

    public function withRowVersion(int $rowVersion): self
    {
        return new self(
            $this->operationId,
            $this->scope,
            $this->purpose,
            $this->owner,
            $this->token(),
            $rowVersion,
        );
    }

    /** @return array{operation_id: string, scope: IntegrationScope, purpose: string, owner: string, token: string, row_version: int} */
    public function __debugInfo(): array
    {
        return [
            'operation_id' => $this->operationId->value,
            'scope' => $this->scope,
            'purpose' => $this->purpose->value,
            'owner' => $this->owner,
            'token' => '[REDACTED]',
            'row_version' => $this->rowVersion,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Lease claims cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Lease claims cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Lease claims cannot be cloned.');
    }
}
