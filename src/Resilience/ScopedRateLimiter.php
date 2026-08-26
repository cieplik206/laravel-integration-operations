<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\Resilience\Contracts\AtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\CorruptResilienceState;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\ResiliencePolicyMismatch;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateMutation;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateSnapshot;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;
use JsonException;

/** @api */
final readonly class ScopedRateLimiter
{
    public function __construct(private AtomicResilienceStateStore $store) {}

    public function acquire(RemoteCallScope $scope, RateLimitPolicy $policy): RateLimitDecision
    {
        return $this->store->transition(
            ResilienceStateKey::rate($scope),
            function (AtomicStateSnapshot $snapshot) use ($policy): AtomicStateMutation {
                $state = $this->decode($snapshot, $policy);

                if ($state['policy'] !== $policy->fingerprint()) {
                    return AtomicStateMutation::preserve(
                        RateLimitDecision::deny(RateLimitRejection::PolicyMismatch, null),
                    );
                }

                $now = max($snapshot->storeTime->milliseconds, $state['last_store_ms']);
                $state['last_store_ms'] = $now;
                $state['sequence'] = $this->increment($state['sequence']);

                if ($state['cooldown_until_ms'] > $now) {
                    return $this->persist(
                        $state,
                        $policy,
                        RateLimitDecision::deny(
                            RateLimitRejection::Cooldown,
                            $this->retryAfter($state['cooldown_until_ms'] - $now),
                        ),
                    );
                }

                $interval = $policy->emissionIntervalMilliseconds();
                $theoreticalArrival = max($state['theoretical_arrival_ms'], $now);
                $burstTolerance = ($policy->burst - 1) * $interval;
                $earliestAllowed = $theoreticalArrival - $burstTolerance;

                if ($now < $earliestAllowed) {
                    return $this->persist(
                        $state,
                        $policy,
                        RateLimitDecision::deny(
                            RateLimitRejection::QuotaExceeded,
                            $this->retryAfter($earliestAllowed - $now),
                        ),
                    );
                }

                if ($theoreticalArrival > PHP_INT_MAX - $interval) {
                    throw new CorruptResilienceState('Rate limit state arithmetic is unsafe.');
                }

                $state['theoretical_arrival_ms'] = $theoreticalArrival + $interval;

                return $this->persist($state, $policy, RateLimitDecision::allow());
            },
        );
    }

    public function suspend(
        RemoteCallScope $scope,
        RateLimitPolicy $policy,
        RetryAfterSeconds $retryAfter,
    ): RateLimitStatus {
        return $this->store->transition(
            ResilienceStateKey::rate($scope),
            function (AtomicStateSnapshot $snapshot) use ($policy, $retryAfter): AtomicStateMutation {
                $state = $this->decode($snapshot, $policy);

                if ($state['policy'] !== $policy->fingerprint()) {
                    throw new ResiliencePolicyMismatch('Rate limit suspension policy does not match state.');
                }

                $now = max($snapshot->storeTime->milliseconds, $state['last_store_ms']);
                $cooldownMilliseconds = $policy->clampCooldownSeconds($retryAfter->value) * 1_000;

                if ($now > PHP_INT_MAX - $cooldownMilliseconds) {
                    throw new CorruptResilienceState('Rate limit cooldown arithmetic is unsafe.');
                }

                $state['last_store_ms'] = $now;
                $state['sequence'] = $this->increment($state['sequence']);
                $state['cooldown_until_ms'] = max(
                    $state['cooldown_until_ms'],
                    $now + $cooldownMilliseconds,
                );

                return $this->persist($state, $policy, $this->statusFrom($state, $snapshot));
            },
        );
    }

    public function status(RemoteCallScope $scope): RateLimitStatus
    {
        $snapshot = $this->store->snapshot(ResilienceStateKey::rate($scope));

        if ($snapshot->encodedState === null) {
            return RateLimitStatus::idle();
        }

        return $this->statusFrom($this->decodeExisting($snapshot->encodedState), $snapshot);
    }

    /**
     * @return array{
     *     v: int,
     *     policy: string,
     *     sequence: int,
     *     last_store_ms: int,
     *     theoretical_arrival_ms: int,
     *     cooldown_until_ms: int
     * }
     */
    private function decode(AtomicStateSnapshot $snapshot, RateLimitPolicy $policy): array
    {
        if ($snapshot->encodedState === null) {
            return [
                'v' => 1,
                'policy' => $policy->fingerprint(),
                'sequence' => 0,
                'last_store_ms' => $snapshot->storeTime->milliseconds,
                'theoretical_arrival_ms' => $snapshot->storeTime->milliseconds,
                'cooldown_until_ms' => 0,
            ];
        }

        return $this->decodeExisting($snapshot->encodedState);
    }

    /**
     * @return array{
     *     v: int,
     *     policy: string,
     *     sequence: int,
     *     last_store_ms: int,
     *     theoretical_arrival_ms: int,
     *     cooldown_until_ms: int
     * }
     */
    private function decodeExisting(string $encodedState): array
    {
        try {
            $state = json_decode($encodedState, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CorruptResilienceState('Rate limit state is not valid JSON.', previous: $exception);
        }

        $expectedKeys = [
            'cooldown_until_ms',
            'last_store_ms',
            'policy',
            'sequence',
            'theoretical_arrival_ms',
            'v',
        ];

        if (! is_array($state) || ! $this->hasExactKeys($state, $expectedKeys)
            || ($state['v'] ?? null) !== 1
            || ! is_string($state['policy'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $state['policy']) !== 1
            || ! $this->isSafeInteger($state['sequence'] ?? null)
            || ! $this->isSafeInteger($state['last_store_ms'] ?? null)
            || ! $this->isSafeInteger($state['theoretical_arrival_ms'] ?? null)
            || ! $this->isSafeInteger($state['cooldown_until_ms'] ?? null)) {
            throw new CorruptResilienceState('Rate limit state schema is invalid.');
        }

        /** @var array{v: int, policy: string, sequence: int, last_store_ms: int, theoretical_arrival_ms: int, cooldown_until_ms: int} $state */
        return $state;
    }

    /**
     * @template TResult
     *
     * @param  array{v: int, policy: string, sequence: int, last_store_ms: int, theoretical_arrival_ms: int, cooldown_until_ms: int}  $state
     * @param  TResult  $result
     * @return AtomicStateMutation<TResult>
     */
    private function persist(array $state, RateLimitPolicy $policy, mixed $result): AtomicStateMutation
    {
        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CorruptResilienceState('Rate limit state cannot be encoded.', previous: $exception);
        }

        return AtomicStateMutation::put($encoded, $policy->stateTtlSeconds * 1_000, $result);
    }

    /** @param array<string, mixed> $state */
    private function statusFrom(array $state, AtomicStateSnapshot $snapshot): RateLimitStatus
    {
        $policy = $state['policy'] ?? null;
        $lastStore = $state['last_store_ms'] ?? null;
        $cooldownUntil = $state['cooldown_until_ms'] ?? null;

        if (! is_string($policy) || ! is_int($lastStore) || ! is_int($cooldownUntil)) {
            throw new CorruptResilienceState('Rate limit status state is invalid.');
        }

        $now = max($snapshot->storeTime->milliseconds, $lastStore);
        $remaining = max(0, $cooldownUntil - $now);

        return new RateLimitStatus(
            true,
            $remaining > 0,
            $remaining > 0 ? $this->retryAfter($remaining) : null,
            $policy,
        );
    }

    private function retryAfter(int $milliseconds): RetryAfterSeconds
    {
        return new RetryAfterSeconds(max(1, intdiv($milliseconds + 999, 1_000)));
    }

    private function increment(int $value): int
    {
        if ($value === PHP_INT_MAX) {
            throw new CorruptResilienceState('Rate limit state sequence is exhausted.');
        }

        return $value + 1;
    }

    /**
     * @param  array<mixed>  $state
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $state, array $expectedKeys): bool
    {
        $keys = array_keys($state);
        sort($keys, SORT_STRING);

        return $keys === $expectedKeys;
    }

    private function isSafeInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value < PHP_INT_MAX;
    }
}
