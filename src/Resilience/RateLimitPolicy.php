<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use InvalidArgumentException;
use JsonException;

/** @api */
final readonly class RateLimitPolicy
{
    public function __construct(
        public int $permits,
        public int $periodSeconds,
        public int $burst,
        public int $minimumCooldownSeconds,
        public int $maximumCooldownSeconds,
        public int $stateTtlSeconds,
    ) {
        if ($permits < 1 || $permits > 10_000
            || $periodSeconds < 1 || $periodSeconds > 86_400
            || $burst < 1 || $burst > 10_000
            || $permits > $periodSeconds * 1_000
            || $minimumCooldownSeconds < 1 || $minimumCooldownSeconds > 86_400
            || $maximumCooldownSeconds < $minimumCooldownSeconds || $maximumCooldownSeconds > 604_800
            || $stateTtlSeconds < max($periodSeconds, $maximumCooldownSeconds)
            || $stateTtlSeconds > 604_800) {
            throw new InvalidArgumentException('Rate limit policy is outside its bounded safe range.');
        }

        $periodMilliseconds = $periodSeconds * 1_000;
        $emissionIntervalMilliseconds = intdiv($periodMilliseconds + $permits - 1, $permits);
        $quotaHorizonMilliseconds = (($burst - 1) * $emissionIntervalMilliseconds) + $periodMilliseconds;

        if ($quotaHorizonMilliseconds > $stateTtlSeconds * 1_000) {
            throw new InvalidArgumentException('Rate limit state TTL cannot preserve its complete quota horizon.');
        }
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'permits' => $this->permits,
            'period_seconds' => $this->periodSeconds,
            'burst' => $this->burst,
            'minimum_cooldown_seconds' => $this->minimumCooldownSeconds,
            'maximum_cooldown_seconds' => $this->maximumCooldownSeconds,
            'state_ttl_seconds' => $this->stateTtlSeconds,
        ], JSON_THROW_ON_ERROR));
    }

    public function emissionIntervalMilliseconds(): int
    {
        return intdiv(($this->periodSeconds * 1_000) + $this->permits - 1, $this->permits);
    }

    public function clampCooldownSeconds(int $hintSeconds): int
    {
        return max(
            $this->minimumCooldownSeconds,
            min($hintSeconds, $this->maximumCooldownSeconds),
        );
    }
}
