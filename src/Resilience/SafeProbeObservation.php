<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;
use InvalidArgumentException;
use LogicException;

/**
 * A sealed observation awaiting a package-owned real transport receipt issuer.
 *
 * @api
 */
final readonly class SafeProbeObservation
{
    private function __construct(
        private ProbeOutcome $outcome,
        private string $permitTokenHash,
        private string $transportReceiptDigest,
        private ?RetryAfterSeconds $retryAfter,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $permitTokenHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $transportReceiptDigest) !== 1
            || ($outcome === ProbeOutcome::Succeeded && $retryAfter !== null)) {
            throw new InvalidArgumentException('Safe probe observation is invalid.');
        }
    }

    public function outcome(): ProbeOutcome
    {
        return $this->outcome;
    }

    /** @internal */
    public function permitTokenHash(): string
    {
        return $this->permitTokenHash;
    }

    public function transportReceiptDigest(): string
    {
        return $this->transportReceiptDigest;
    }

    public function retryAfter(): ?RetryAfterSeconds
    {
        return $this->retryAfter;
    }

    /** @return array{outcome: string, permit: string, transport_receipt: string} */
    public function __debugInfo(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'permit' => '[REDACTED]',
            'transport_receipt' => '[REDACTED]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Safe probe observations cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Safe probe observations cannot be unserialized.');
    }

    public function __wakeup(): never
    {
        throw new LogicException('Safe probe observations cannot be unserialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Safe probe observations cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Safe probe observations cannot be cloned.');
    }
}
