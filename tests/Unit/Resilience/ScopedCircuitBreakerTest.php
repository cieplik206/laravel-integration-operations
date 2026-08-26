<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Resilience\CircuitBreakerPolicy;
use Cieplik206\IntegrationOperations\Resilience\CircuitCallPermit;
use Cieplik206\IntegrationOperations\Resilience\CircuitRejection;
use Cieplik206\IntegrationOperations\Resilience\CircuitState;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\CorruptResilienceState;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\InvalidResiliencePermit;
use Cieplik206\IntegrationOperations\Resilience\HalfOpenProbePermit;
use Cieplik206\IntegrationOperations\Resilience\ProbeOutcome;
use Cieplik206\IntegrationOperations\Resilience\RemoteCallKind;
use Cieplik206\IntegrationOperations\Resilience\RemoteCallScope;
use Cieplik206\IntegrationOperations\Resilience\SafeProbeObservation;
use Cieplik206\IntegrationOperations\Resilience\ScopedCircuitBreaker;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Cieplik206\IntegrationOperations\Testing\Resilience\InMemoryAtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Testing\Resilience\ManualStoreTime;
use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;

function s92CircuitPolicy(
    int $threshold = 2,
    int $baseCooldown = 2,
    int $maximumCooldown = 8,
    int $callLease = 5,
    int $probeLease = 3,
    int $maximumInFlight = 4,
): CircuitBreakerPolicy {
    return new CircuitBreakerPolicy(
        $threshold,
        $baseCooldown,
        $maximumCooldown,
        $callLease,
        $probeLease,
        $maximumInFlight,
        60,
    );
}

function s92SealedObservation(
    HalfOpenProbePermit $permit,
    ProbeOutcome $outcome,
    ?RetryAfterSeconds $retryAfter = null,
): SafeProbeObservation {
    $reflection = new ReflectionClass(SafeProbeObservation::class);
    $observation = $reflection->newInstanceWithoutConstructor();
    $constructor = $reflection->getConstructor();

    if ($constructor === null) {
        throw new RuntimeException('Safe probe observation constructor is missing.');
    }

    $constructor->invoke(
        $observation,
        $outcome,
        $permit->tokenHash(),
        hash('sha256', 'test-only-transport-receipt'),
        $retryAfter,
    );

    return $observation;
}

it('requires an atomically stored one-shot call permit for every outcome', function (): void {
    $time = new ManualStoreTime;
    $breaker = new ScopedCircuitBreaker(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $policy = s92CircuitPolicy();
    $permit = $breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->permit();

    expect($permit)->toBeInstanceOf(CircuitCallPermit::class);

    $forged = new CircuitCallPermit(
        $permit->scopeFingerprint,
        $permit->kind,
        $permit->policyFingerprint,
        $permit->generation,
        $permit->expiresAtMilliseconds,
        str_repeat('f', 64),
    );

    expect(fn () => $breaker->recordSuccess($forged))
        ->toThrow(InvalidResiliencePermit::class);

    $status = $breaker->recordSuccess($permit);

    expect($status->activeCallPermits)->toBe(0)
        ->and(fn () => $breaker->recordSuccess($permit))->toThrow(InvalidResiliencePermit::class);
});

it('rejects cross-scope cross-kind and expired call permit forgeries', function (): void {
    $time = new ManualStoreTime;
    $breaker = new ScopedCircuitBreaker(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $otherScope = RemoteCallScope::of('fakturownia', 'tenant-b', 'invoices');
    $policy = s92CircuitPolicy();
    $permit = $breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->permit();

    expect($permit)->toBeInstanceOf(CircuitCallPermit::class);

    $crossScope = new CircuitCallPermit(
        $otherScope->fingerprint(),
        $permit->kind,
        $permit->policyFingerprint,
        $permit->generation,
        $permit->expiresAtMilliseconds,
        str_repeat('a', 64),
    );
    $crossKind = new CircuitCallPermit(
        $permit->scopeFingerprint,
        RemoteCallKind::Read,
        $permit->policyFingerprint,
        $permit->generation,
        $permit->expiresAtMilliseconds,
        str_repeat('b', 64),
    );

    expect(fn () => $breaker->recordSuccess($crossScope))->toThrow(InvalidResiliencePermit::class)
        ->and(fn () => $breaker->recordSuccess($crossKind))->toThrow(InvalidResiliencePermit::class);

    $time->advanceMilliseconds(5_000);

    expect(fn () => $breaker->recordFailure($permit))->toThrow(InvalidResiliencePermit::class);
});

it('opens with bounded cooldown and blocks mutations until a sealed positive probe', function (): void {
    $time = new ManualStoreTime;
    $breaker = new ScopedCircuitBreaker(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $policy = s92CircuitPolicy(threshold: 1);
    $call = $breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->permit();

    expect($call)->toBeInstanceOf(CircuitCallPermit::class);

    $opened = $breaker->recordFailure($call, new RetryAfterSeconds(600));

    expect($opened->state)->toBe(CircuitState::Open)
        ->and($opened->retryAfter?->value)->toBe(8)
        ->and($breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->rejection())->toBe(CircuitRejection::Open);

    $time->advanceMilliseconds(8_000);

    expect($breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->rejection())->toBe(CircuitRejection::ProbeRequired)
        ->and($breaker->acquire($scope, RemoteCallKind::Read, $policy)->rejection())->toBe(CircuitRejection::ProbeRequired);

    $probe = $breaker->acquireProbe($scope, $policy)->permit();

    expect($probe)->toBeInstanceOf(HalfOpenProbePermit::class)
        ->and($breaker->acquireProbe($scope, $policy)->rejection())->toBe(CircuitRejection::ProbeInProgress)
        ->and($breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->rejection())->toBe(CircuitRejection::ProbeInProgress);

    $closed = $breaker->recordProbeObservation(
        $probe,
        s92SealedObservation($probe, ProbeOutcome::Succeeded),
    );

    expect($closed->state)->toBe(CircuitState::Closed)
        ->and($breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->allowed())->toBeTrue()
        ->and(fn () => $breaker->recordProbeObservation(
            $probe,
            s92SealedObservation($probe, ProbeOutcome::Succeeded),
        ))->toThrow(InvalidResiliencePermit::class);
});

it('keeps half-open fail closed when no transport observation issuer completes the probe', function (): void {
    $time = new ManualStoreTime;
    $breaker = new ScopedCircuitBreaker(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $policy = s92CircuitPolicy(threshold: 1);
    $call = $breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->permit();

    expect($call)->toBeInstanceOf(CircuitCallPermit::class);
    $breaker->recordFailure($call);
    $time->advanceMilliseconds(2_000);

    $firstProbe = $breaker->acquireProbe($scope, $policy)->permit();

    expect($firstProbe)->toBeInstanceOf(HalfOpenProbePermit::class);
    $time->advanceMilliseconds(3_000);

    expect($breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->rejection())->toBe(CircuitRejection::ProbeRequired)
        ->and($breaker->acquireProbe($scope, $policy)->allowed())->toBeTrue();
});

it('pins circuit policy and never resets a failure counter on drift', function (): void {
    $time = new ManualStoreTime;
    $breaker = new ScopedCircuitBreaker(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $policy = s92CircuitPolicy();
    $call = $breaker->acquire($scope, RemoteCallKind::Read, $policy)->permit();

    expect($call)->toBeInstanceOf(CircuitCallPermit::class);
    $breaker->recordFailure($call);

    $drift = $breaker->acquire($scope, RemoteCallKind::Read, s92CircuitPolicy(threshold: 10));

    expect($drift->rejection())->toBe(CircuitRejection::PolicyMismatch)
        ->and($breaker->status($scope)->consecutiveFailures)->toBe(1);
});

it('fails closed for hostile circuit schema', function (string $hostileState): void {
    $time = new ManualStoreTime;
    $store = new InMemoryAtomicResilienceStateStore($time);
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $store->putRaw(ResilienceStateKey::circuit($scope), $hostileState);
    $breaker = new ScopedCircuitBreaker($store);

    expect(fn () => $breaker->acquire($scope, RemoteCallKind::Read, s92CircuitPolicy()))
        ->toThrow(CorruptResilienceState::class);
})->with([
    'not json' => 'not-json',
    'unknown version' => '{"v":2}',
    'native object envelope' => 'O:8:"stdClass":0:{}',
]);
