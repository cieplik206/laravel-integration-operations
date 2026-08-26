<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use InvalidArgumentException;
use LogicException;

/**
 * Kernel-owned mutable lease holder. It is never exposed through provider SPI.
 *
 * @internal
 */
final class LeaseClaimHandle
{
    public function __construct(private LeaseClaim $claim) {}

    public function claim(): LeaseClaim
    {
        return $this->claim;
    }

    public function advanceTo(int $rowVersion): void
    {
        if ($rowVersion <= $this->claim->rowVersion) {
            throw new InvalidArgumentException('Lease claim row version must advance monotonically.');
        }

        $this->claim = $this->claim->withRowVersion($rowVersion);
    }

    /** @return array{operation_id: string, scope: object, row_version: int, token: string} */
    public function __debugInfo(): array
    {
        return [
            'operation_id' => $this->claim->operationId->value,
            'scope' => $this->claim->scope,
            'row_version' => $this->claim->rowVersion,
            'token' => '[REDACTED]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Lease claim handles cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Lease claim handles cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Lease claim handles cannot be cloned.');
    }
}
