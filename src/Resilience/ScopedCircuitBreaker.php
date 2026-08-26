<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\Resilience\Contracts\AtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\CorruptResilienceState;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\InvalidResiliencePermit;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateMutation;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateSnapshot;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;
use InvalidArgumentException;
use JsonException;

/**
 * @phpstan-type CircuitPolicyValues array{failure_threshold: int, base_cooldown_seconds: int, maximum_cooldown_seconds: int, call_permit_lease_seconds: int, probe_lease_seconds: int, maximum_in_flight_permits: int, state_ttl_seconds: int}
 * @phpstan-type CircuitProbe array{token_hash: string, generation: int, expires_at_ms: int}
 * @phpstan-type CircuitCallPermitState array{kind: string, generation: int, expires_at_ms: int}
 * @phpstan-type CircuitStateData array{v: int, policy: string, policy_values: CircuitPolicyValues, sequence: int, last_store_ms: int, generation: int, state: string, failures: int, open_count: int, open_until_ms: int, probe: CircuitProbe|null, permits: array<string, CircuitCallPermitState>}
 *
 * @api
 */
final readonly class ScopedCircuitBreaker
{
    public function __construct(private AtomicResilienceStateStore $store) {}

    public function acquire(
        RemoteCallScope $scope,
        RemoteCallKind $kind,
        CircuitBreakerPolicy $policy,
    ): CircuitDecision {
        return $this->store->transition(
            ResilienceStateKey::circuit($scope),
            function (AtomicStateSnapshot $snapshot) use ($scope, $kind, $policy): AtomicStateMutation {
                $state = $this->decode($snapshot, $policy);

                if ($state['policy'] !== $policy->fingerprint()) {
                    return AtomicStateMutation::preserve(
                        CircuitDecision::deny(CircuitRejection::PolicyMismatch, null),
                    );
                }

                $now = $this->effectiveNow($state, $snapshot);
                $this->pruneCallPermits($state, $now);
                $this->expireProbe($state, $now);

                if ($state['state'] === CircuitState::Open->value) {
                    $remaining = $this->remainingMilliseconds($state['open_until_ms'], $now);
                    $decision = $remaining > 0
                        ? CircuitDecision::deny(CircuitRejection::Open, $this->retryAfter($remaining))
                        : CircuitDecision::deny(CircuitRejection::ProbeRequired, null);

                    return $this->persist($state, $decision);
                }

                if ($state['state'] === CircuitState::HalfOpen->value) {
                    $remaining = max(1, (int) ($state['probe']['expires_at_ms'] ?? $now + 1) - $now);

                    return $this->persist(
                        $state,
                        CircuitDecision::deny(CircuitRejection::ProbeInProgress, $this->retryAfter($remaining)),
                    );
                }

                $statePolicy = $this->policyFromState($state);

                if (count($state['permits']) >= $statePolicy->maximumInFlightPermits) {
                    return $this->persist(
                        $state,
                        CircuitDecision::deny(
                            CircuitRejection::InFlightCapacity,
                            new RetryAfterSeconds(1),
                        ),
                    );
                }

                $token = bin2hex(random_bytes(32));
                $expiresAt = $this->addMilliseconds($now, $statePolicy->callPermitLeaseSeconds * 1_000);
                $permit = new CircuitCallPermit(
                    $scope->fingerprint(),
                    $kind,
                    $state['policy'],
                    $state['generation'],
                    $expiresAt,
                    $token,
                );
                $state['permits'][$permit->tokenHash()] = [
                    'kind' => $kind->value,
                    'generation' => $state['generation'],
                    'expires_at_ms' => $expiresAt,
                ];

                return $this->persist($state, CircuitDecision::allow($permit));
            },
        );
    }

    public function acquireProbe(
        RemoteCallScope $scope,
        CircuitBreakerPolicy $policy,
    ): HalfOpenProbeDecision {
        return $this->store->transition(
            ResilienceStateKey::circuit($scope),
            function (AtomicStateSnapshot $snapshot) use ($scope, $policy): AtomicStateMutation {
                $state = $this->decode($snapshot, $policy);

                if ($state['policy'] !== $policy->fingerprint()) {
                    return AtomicStateMutation::preserve(
                        HalfOpenProbeDecision::deny(CircuitRejection::PolicyMismatch, null),
                    );
                }

                $now = $this->effectiveNow($state, $snapshot);
                $this->pruneCallPermits($state, $now);
                $this->expireProbe($state, $now);

                if ($state['state'] === CircuitState::Closed->value) {
                    return $this->persist(
                        $state,
                        HalfOpenProbeDecision::deny(CircuitRejection::NotOpen, null),
                    );
                }

                if ($state['state'] === CircuitState::HalfOpen->value) {
                    $remaining = max(1, (int) ($state['probe']['expires_at_ms'] ?? $now + 1) - $now);

                    return $this->persist(
                        $state,
                        HalfOpenProbeDecision::deny(
                            CircuitRejection::ProbeInProgress,
                            $this->retryAfter($remaining),
                        ),
                    );
                }

                $remaining = $this->remainingMilliseconds($state['open_until_ms'], $now);

                if ($remaining > 0) {
                    return $this->persist(
                        $state,
                        HalfOpenProbeDecision::deny(CircuitRejection::Open, $this->retryAfter($remaining)),
                    );
                }

                $statePolicy = $this->policyFromState($state);
                $state['generation'] = $this->increment($state['generation'], 'generation');
                $state['state'] = CircuitState::HalfOpen->value;
                $state['permits'] = [];
                $token = bin2hex(random_bytes(32));
                $expiresAt = $this->addMilliseconds($now, $statePolicy->probeLeaseSeconds * 1_000);
                $permit = new HalfOpenProbePermit(
                    $scope->fingerprint(),
                    $state['policy'],
                    $state['generation'],
                    $expiresAt,
                    $token,
                );
                $state['probe'] = [
                    'token_hash' => $permit->tokenHash(),
                    'generation' => $state['generation'],
                    'expires_at_ms' => $expiresAt,
                ];

                return $this->persist($state, HalfOpenProbeDecision::allow($permit));
            },
        );
    }

    public function recordSuccess(CircuitCallPermit $permit): CircuitStatus
    {
        return $this->recordCallOutcome($permit, true, null);
    }

    public function recordFailure(
        CircuitCallPermit $permit,
        ?RetryAfterSeconds $retryAfter = null,
    ): CircuitStatus {
        return $this->recordCallOutcome($permit, false, $retryAfter);
    }

    public function abandon(CircuitCallPermit $permit): CircuitStatus
    {
        return $this->store->transition(
            ResilienceStateKey::circuitFingerprint($permit->scopeFingerprint),
            function (AtomicStateSnapshot $snapshot) use ($permit): AtomicStateMutation {
                $state = $this->decodePermitState($snapshot, $permit);
                $now = $this->effectiveNow($state, $snapshot);
                $this->consumeCallPermit($state, $permit, $now);

                return $this->persist($state, $this->statusFrom($state, $now));
            },
        );
    }

    public function recordProbeObservation(
        HalfOpenProbePermit $permit,
        SafeProbeObservation $observation,
    ): CircuitStatus {
        return $this->store->transition(
            ResilienceStateKey::circuitFingerprint($permit->scopeFingerprint),
            function (AtomicStateSnapshot $snapshot) use ($permit, $observation): AtomicStateMutation {
                $state = $this->decodeProbePermitState($snapshot, $permit);
                $now = $this->effectiveNow($state, $snapshot);

                if ($observation->permitTokenHash() !== $permit->tokenHash()) {
                    throw new InvalidResiliencePermit('Probe observation is not bound to this permit.');
                }

                $probe = $state['probe'];

                if ($state['state'] !== CircuitState::HalfOpen->value
                    || ! is_array($probe)
                    || $probe['token_hash'] !== $permit->tokenHash()
                    || $probe['generation'] !== $permit->generation
                    || $probe['expires_at_ms'] !== $permit->expiresAtMilliseconds
                    || $permit->expiresAtMilliseconds <= $now) {
                    throw new InvalidResiliencePermit('Half-open probe permit is stale or invalid.');
                }

                $state['generation'] = $this->increment($state['generation'], 'generation');
                $state['probe'] = null;
                $state['permits'] = [];

                if ($observation->outcome() === ProbeOutcome::Succeeded) {
                    $state['state'] = CircuitState::Closed->value;
                    $state['failures'] = 0;
                    $state['open_count'] = 0;
                    $state['open_until_ms'] = 0;
                } else {
                    $statePolicy = $this->policyFromState($state);
                    $state['state'] = CircuitState::Open->value;
                    $state['open_count'] = $this->increment($state['open_count'], 'open count');
                    $state['open_until_ms'] = $this->addMilliseconds(
                        $now,
                        $statePolicy->cooldownSeconds(
                            $state['open_count'],
                            $observation->retryAfter()?->value,
                        ) * 1_000,
                    );
                }

                return $this->persist($state, $this->statusFrom($state, $now));
            },
        );
    }

    public function status(RemoteCallScope $scope): CircuitStatus
    {
        $snapshot = $this->store->snapshot(ResilienceStateKey::circuit($scope));

        if ($snapshot->encodedState === null) {
            return CircuitStatus::idle();
        }

        $state = $this->decodeExisting($snapshot->encodedState);
        $now = max($snapshot->storeTime->milliseconds, $state['last_store_ms']);

        return $this->statusFrom($state, $now);
    }

    private function recordCallOutcome(
        CircuitCallPermit $permit,
        bool $succeeded,
        ?RetryAfterSeconds $retryAfter,
    ): CircuitStatus {
        return $this->store->transition(
            ResilienceStateKey::circuitFingerprint($permit->scopeFingerprint),
            function (AtomicStateSnapshot $snapshot) use ($permit, $succeeded, $retryAfter): AtomicStateMutation {
                $state = $this->decodePermitState($snapshot, $permit);
                $now = $this->effectiveNow($state, $snapshot);
                $this->consumeCallPermit($state, $permit, $now);

                if ($succeeded) {
                    $state['failures'] = 0;

                    return $this->persist($state, $this->statusFrom($state, $now));
                }

                $state['failures'] = $this->increment($state['failures'], 'failure count');
                $statePolicy = $this->policyFromState($state);

                if ($state['failures'] >= $statePolicy->failureThreshold) {
                    $state['generation'] = $this->increment($state['generation'], 'generation');
                    $state['state'] = CircuitState::Open->value;
                    $state['open_count'] = $this->increment($state['open_count'], 'open count');
                    $state['open_until_ms'] = $this->addMilliseconds(
                        $now,
                        $statePolicy->cooldownSeconds($state['open_count'], $retryAfter?->value) * 1_000,
                    );
                    $state['probe'] = null;
                    $state['permits'] = [];
                }

                return $this->persist($state, $this->statusFrom($state, $now));
            },
        );
    }

    /**
     * @return array{
     *     v: int,
     *     policy: string,
     *     policy_values: array{failure_threshold: int, base_cooldown_seconds: int, maximum_cooldown_seconds: int, call_permit_lease_seconds: int, probe_lease_seconds: int, maximum_in_flight_permits: int, state_ttl_seconds: int},
     *     sequence: int,
     *     last_store_ms: int,
     *     generation: int,
     *     state: string,
     *     failures: int,
     *     open_count: int,
     *     open_until_ms: int,
     *     probe: array{token_hash: string, generation: int, expires_at_ms: int}|null,
     *     permits: array<string, array{kind: string, generation: int, expires_at_ms: int}>
     * }
     */
    private function decode(AtomicStateSnapshot $snapshot, CircuitBreakerPolicy $policy): array
    {
        if ($snapshot->encodedState === null) {
            return [
                'v' => 1,
                'policy' => $policy->fingerprint(),
                'policy_values' => $this->policyValues($policy),
                'sequence' => 0,
                'last_store_ms' => $snapshot->storeTime->milliseconds,
                'generation' => 1,
                'state' => CircuitState::Closed->value,
                'failures' => 0,
                'open_count' => 0,
                'open_until_ms' => 0,
                'probe' => null,
                'permits' => [],
            ];
        }

        return $this->decodeExisting($snapshot->encodedState);
    }

    /** @return CircuitStateData */
    private function decodeExisting(string $encodedState): array
    {
        try {
            $state = json_decode($encodedState, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CorruptResilienceState('Circuit state is not valid JSON.', previous: $exception);
        }

        $rootKeys = [
            'failures',
            'generation',
            'last_store_ms',
            'open_count',
            'open_until_ms',
            'permits',
            'policy',
            'policy_values',
            'probe',
            'sequence',
            'state',
            'v',
        ];

        if (! is_array($state) || ! $this->hasExactKeys($state, $rootKeys)
            || ($state['v'] ?? null) !== 1
            || ! is_string($state['policy'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $state['policy']) !== 1
            || ! is_array($state['policy_values'] ?? null)
            || ! $this->isSafeInteger($state['sequence'] ?? null)
            || ! $this->isSafeInteger($state['last_store_ms'] ?? null)
            || ! is_int($state['generation'] ?? null) || $state['generation'] < 1
            || ! is_string($state['state'] ?? null) || CircuitState::tryFrom($state['state']) === null
            || ! $this->isSafeInteger($state['failures'] ?? null)
            || ! $this->isSafeInteger($state['open_count'] ?? null)
            || ! $this->isSafeInteger($state['open_until_ms'] ?? null)
            || ! is_array($state['permits'] ?? null)
            || count($state['permits']) > 1_024) {
            throw new CorruptResilienceState('Circuit state schema is invalid.');
        }

        $policy = $this->policyFromValues($state['policy_values']);

        if ($policy->fingerprint() !== $state['policy']) {
            throw new CorruptResilienceState('Circuit state policy fingerprint is invalid.');
        }

        $this->validateProbe($state['probe'], $state['state']);
        $this->validatePermits($state['permits'], $state['state']);

        /** @var array{v: int, policy: string, policy_values: array{failure_threshold: int, base_cooldown_seconds: int, maximum_cooldown_seconds: int, call_permit_lease_seconds: int, probe_lease_seconds: int, maximum_in_flight_permits: int, state_ttl_seconds: int}, sequence: int, last_store_ms: int, generation: int, state: string, failures: int, open_count: int, open_until_ms: int, probe: array{token_hash: string, generation: int, expires_at_ms: int}|null, permits: array<string, array{kind: string, generation: int, expires_at_ms: int}>} $state */
        return $state;
    }

    /** @param array<string, mixed> $state */
    private function policyFromState(array $state): CircuitBreakerPolicy
    {
        $values = $state['policy_values'] ?? null;

        if (! is_array($values)) {
            throw new CorruptResilienceState('Circuit state policy is missing.');
        }

        return $this->policyFromValues($values);
    }

    /** @param array<mixed> $values */
    private function policyFromValues(array $values): CircuitBreakerPolicy
    {
        $keys = [
            'base_cooldown_seconds',
            'call_permit_lease_seconds',
            'failure_threshold',
            'maximum_cooldown_seconds',
            'maximum_in_flight_permits',
            'probe_lease_seconds',
            'state_ttl_seconds',
        ];

        if (! $this->hasExactKeys($values, $keys)) {
            throw new CorruptResilienceState('Circuit state policy schema is invalid.');
        }

        foreach ($keys as $key) {
            if (! is_int($values[$key] ?? null)) {
                throw new CorruptResilienceState('Circuit state policy value is invalid.');
            }
        }

        try {
            return new CircuitBreakerPolicy(
                $values['failure_threshold'],
                $values['base_cooldown_seconds'],
                $values['maximum_cooldown_seconds'],
                $values['call_permit_lease_seconds'],
                $values['probe_lease_seconds'],
                $values['maximum_in_flight_permits'],
                $values['state_ttl_seconds'],
            );
        } catch (InvalidArgumentException $exception) {
            throw new CorruptResilienceState('Circuit state policy values are unsafe.', previous: $exception);
        }
    }

    /**
     * @return array{failure_threshold: int, base_cooldown_seconds: int, maximum_cooldown_seconds: int, call_permit_lease_seconds: int, probe_lease_seconds: int, maximum_in_flight_permits: int, state_ttl_seconds: int}
     */
    private function policyValues(CircuitBreakerPolicy $policy): array
    {
        return [
            'failure_threshold' => $policy->failureThreshold,
            'base_cooldown_seconds' => $policy->baseCooldownSeconds,
            'maximum_cooldown_seconds' => $policy->maximumCooldownSeconds,
            'call_permit_lease_seconds' => $policy->callPermitLeaseSeconds,
            'probe_lease_seconds' => $policy->probeLeaseSeconds,
            'maximum_in_flight_permits' => $policy->maximumInFlightPermits,
            'state_ttl_seconds' => $policy->stateTtlSeconds,
        ];
    }

    /** @param array<string, mixed> $state */
    private function effectiveNow(array &$state, AtomicStateSnapshot $snapshot): int
    {
        $lastStore = $state['last_store_ms'] ?? null;

        if (! is_int($lastStore)) {
            throw new CorruptResilienceState('Circuit store time watermark is invalid.');
        }

        $now = max($snapshot->storeTime->milliseconds, $lastStore);
        $state['last_store_ms'] = $now;
        $sequence = $state['sequence'] ?? null;

        if (! is_int($sequence)) {
            throw new CorruptResilienceState('Circuit state sequence is invalid.');
        }

        $state['sequence'] = $this->increment($sequence, 'sequence');

        return $now;
    }

    /** @param array<string, mixed> $state */
    private function pruneCallPermits(array &$state, int $now): void
    {
        $permits = $state['permits'] ?? null;
        $generation = $state['generation'] ?? null;

        if (! is_array($permits) || ! is_int($generation)) {
            throw new CorruptResilienceState('Circuit call permits are invalid.');
        }

        foreach ($permits as $hash => $permit) {
            if (! is_array($permit)
                || ($permit['expires_at_ms'] ?? 0) <= $now
                || ($permit['generation'] ?? 0) !== $generation) {
                unset($permits[$hash]);
            }
        }

        $state['permits'] = $permits;
    }

    /** @param array<string, mixed> $state */
    private function expireProbe(array &$state, int $now): void
    {
        if (($state['state'] ?? null) !== CircuitState::HalfOpen->value) {
            return;
        }

        $probe = $state['probe'] ?? null;

        if (! is_array($probe) || ! is_int($probe['expires_at_ms'] ?? null)) {
            throw new CorruptResilienceState('Half-open probe state is invalid.');
        }

        if ($probe['expires_at_ms'] > $now) {
            return;
        }

        $state['state'] = CircuitState::Open->value;
        $state['probe'] = null;
        $state['open_until_ms'] = $now;
        $state['generation'] = $this->increment((int) $state['generation'], 'generation');
    }

    /** @param array<string, mixed> $state */
    private function consumeCallPermit(array &$state, CircuitCallPermit $permit, int $now): void
    {
        $hash = $permit->tokenHash();
        $stored = $state['permits'][$hash] ?? null;

        if ($state['state'] !== CircuitState::Closed->value
            || $state['policy'] !== $permit->policyFingerprint
            || $state['generation'] !== $permit->generation
            || ! is_array($stored)
            || ($stored['kind'] ?? null) !== $permit->kind->value
            || ($stored['generation'] ?? null) !== $permit->generation
            || ($stored['expires_at_ms'] ?? null) !== $permit->expiresAtMilliseconds
            || $permit->expiresAtMilliseconds <= $now) {
            throw new InvalidResiliencePermit('Circuit call permit is stale or invalid.');
        }

        unset($state['permits'][$hash]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePermitState(AtomicStateSnapshot $snapshot, CircuitCallPermit $permit): array
    {
        if ($snapshot->encodedState === null) {
            throw new InvalidResiliencePermit('Circuit call permit state has expired.');
        }

        $state = $this->decodeExisting($snapshot->encodedState);

        if ($state['policy'] !== $permit->policyFingerprint) {
            throw new InvalidResiliencePermit('Circuit call permit policy does not match state.');
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProbePermitState(AtomicStateSnapshot $snapshot, HalfOpenProbePermit $permit): array
    {
        if ($snapshot->encodedState === null) {
            throw new InvalidResiliencePermit('Half-open probe permit state has expired.');
        }

        $state = $this->decodeExisting($snapshot->encodedState);

        if ($state['policy'] !== $permit->policyFingerprint) {
            throw new InvalidResiliencePermit('Half-open probe permit policy does not match state.');
        }

        return $state;
    }

    /**
     * @template TResult
     *
     * @param  array<string, mixed>  $state
     * @param  TResult  $result
     * @return AtomicStateMutation<TResult>
     */
    private function persist(array $state, mixed $result): AtomicStateMutation
    {
        $policy = $this->policyFromState($state);

        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CorruptResilienceState('Circuit state cannot be encoded.', previous: $exception);
        }

        return AtomicStateMutation::put($encoded, $policy->stateTtlSeconds * 1_000, $result);
    }

    /** @param array<string, mixed> $state */
    private function statusFrom(array $state, int $now): CircuitStatus
    {
        $stateValue = $state['state'] ?? null;
        $circuitState = is_string($stateValue) ? CircuitState::tryFrom($stateValue) : null;

        if ($circuitState === null
            || ! is_int($state['failures'] ?? null)
            || ! is_array($state['permits'] ?? null)
            || ! is_string($state['policy'] ?? null)) {
            throw new CorruptResilienceState('Circuit status state is invalid.');
        }

        $remaining = 0;

        if ($circuitState === CircuitState::Open && is_int($state['open_until_ms'] ?? null)) {
            $remaining = max(0, $state['open_until_ms'] - $now);
        } elseif ($circuitState === CircuitState::HalfOpen
            && is_array($state['probe'] ?? null)
            && is_int($state['probe']['expires_at_ms'] ?? null)) {
            $remaining = max(0, $state['probe']['expires_at_ms'] - $now);

            if ($remaining === 0) {
                $circuitState = CircuitState::Open;
            }
        }

        $activePermits = 0;

        foreach ($state['permits'] as $permit) {
            if (is_array($permit) && is_int($permit['expires_at_ms'] ?? null) && $permit['expires_at_ms'] > $now) {
                $activePermits++;
            }
        }

        return new CircuitStatus(
            true,
            $circuitState,
            $state['failures'],
            $activePermits,
            $remaining > 0 ? $this->retryAfter($remaining) : null,
            $state['policy'],
        );
    }

    private function validateProbe(mixed $probe, string $state): void
    {
        if ($state !== CircuitState::HalfOpen->value) {
            if ($probe !== null) {
                throw new CorruptResilienceState('Non-half-open circuit cannot hold a probe permit.');
            }

            return;
        }

        $keys = ['expires_at_ms', 'generation', 'token_hash'];

        if (! is_array($probe) || ! $this->hasExactKeys($probe, $keys)
            || ! is_string($probe['token_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $probe['token_hash']) !== 1
            || ! is_int($probe['generation'] ?? null) || $probe['generation'] < 1
            || ! $this->isSafeInteger($probe['expires_at_ms'] ?? null)) {
            throw new CorruptResilienceState('Half-open probe schema is invalid.');
        }
    }

    /** @param array<mixed> $permits */
    private function validatePermits(array $permits, string $state): void
    {
        if ($state !== CircuitState::Closed->value && $permits !== []) {
            throw new CorruptResilienceState('Open circuit cannot hold call permits.');
        }

        foreach ($permits as $hash => $permit) {
            if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
                || ! is_array($permit)
                || ! $this->hasExactKeys($permit, ['expires_at_ms', 'generation', 'kind'])
                || ! is_string($permit['kind'] ?? null)
                || RemoteCallKind::tryFrom($permit['kind']) === null
                || ! is_int($permit['generation'] ?? null) || $permit['generation'] < 1
                || ! $this->isSafeInteger($permit['expires_at_ms'] ?? null)) {
                throw new CorruptResilienceState('Circuit call permit schema is invalid.');
            }
        }
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

    private function increment(int $value, string $field): int
    {
        if ($value === PHP_INT_MAX) {
            throw new CorruptResilienceState("Circuit {$field} is exhausted.");
        }

        return $value + 1;
    }

    private function addMilliseconds(int $instant, int $duration): int
    {
        if ($duration < 0 || $instant > PHP_INT_MAX - $duration) {
            throw new CorruptResilienceState('Circuit time arithmetic is unsafe.');
        }

        return $instant + $duration;
    }

    private function retryAfter(int $milliseconds): RetryAfterSeconds
    {
        return new RetryAfterSeconds(max(1, intdiv($milliseconds + 999, 1_000)));
    }

    private function remainingMilliseconds(int $until, int $now): int
    {
        if ($until <= $now) {
            return 0;
        }

        return $until - $now;
    }
}
