<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use InvalidArgumentException;
use JsonException;

/** @api */
final readonly class CircuitBreakerPolicy
{
    public function __construct(
        public int $failureThreshold,
        public int $baseCooldownSeconds,
        public int $maximumCooldownSeconds,
        public int $callPermitLeaseSeconds,
        public int $probeLeaseSeconds,
        public int $maximumInFlightPermits,
        public int $stateTtlSeconds,
    ) {
        if ($failureThreshold < 1 || $failureThreshold > 1_000
            || $baseCooldownSeconds < 1 || $baseCooldownSeconds > 86_400
            || $maximumCooldownSeconds < $baseCooldownSeconds || $maximumCooldownSeconds > 604_800
            || $callPermitLeaseSeconds < 1 || $callPermitLeaseSeconds > 3_600
            || $probeLeaseSeconds < 1 || $probeLeaseSeconds > 3_600
            || $maximumInFlightPermits < 1 || $maximumInFlightPermits > 1_024
            || $stateTtlSeconds < max($maximumCooldownSeconds, $callPermitLeaseSeconds, $probeLeaseSeconds)
            || $stateTtlSeconds > 604_800) {
            throw new InvalidArgumentException('Circuit breaker policy is outside its bounded safe range.');
        }
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'failure_threshold' => $this->failureThreshold,
            'base_cooldown_seconds' => $this->baseCooldownSeconds,
            'maximum_cooldown_seconds' => $this->maximumCooldownSeconds,
            'call_permit_lease_seconds' => $this->callPermitLeaseSeconds,
            'probe_lease_seconds' => $this->probeLeaseSeconds,
            'maximum_in_flight_permits' => $this->maximumInFlightPermits,
            'state_ttl_seconds' => $this->stateTtlSeconds,
        ], JSON_THROW_ON_ERROR));
    }

    public function cooldownSeconds(int $openCount, ?int $retryAfterSeconds): int
    {
        $cooldown = $this->baseCooldownSeconds;

        for ($attempt = 1; $attempt < $openCount && $cooldown < $this->maximumCooldownSeconds; $attempt++) {
            $cooldown = min($this->maximumCooldownSeconds, $cooldown * 2);
        }

        if ($retryAfterSeconds !== null) {
            $cooldown = max(
                $cooldown,
                max($this->baseCooldownSeconds, min($retryAfterSeconds, $this->maximumCooldownSeconds)),
            );
        }

        return $cooldown;
    }
}
