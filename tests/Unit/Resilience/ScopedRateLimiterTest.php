<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Resilience\Exceptions\CorruptResilienceState;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\ResiliencePolicyMismatch;
use Cieplik206\IntegrationOperations\Resilience\RateLimitPolicy;
use Cieplik206\IntegrationOperations\Resilience\RateLimitRejection;
use Cieplik206\IntegrationOperations\Resilience\RemoteCallScope;
use Cieplik206\IntegrationOperations\Resilience\ScopedRateLimiter;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Cieplik206\IntegrationOperations\Testing\Resilience\InMemoryAtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Testing\Resilience\ManualStoreTime;
use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;

function s92RatePolicy(
    int $permits = 1,
    int $periodSeconds = 10,
    int $burst = 1,
    int $minimumCooldown = 2,
    int $maximumCooldown = 30,
): RateLimitPolicy {
    return new RateLimitPolicy(
        $permits,
        $periodSeconds,
        $burst,
        $minimumCooldown,
        $maximumCooldown,
        60,
    );
}

it('isolates quota state by provider connection and endpoint family', function (): void {
    $time = new ManualStoreTime;
    $limiter = new ScopedRateLimiter(new InMemoryAtomicResilienceStateStore($time));
    $policy = s92RatePolicy();
    $limited = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');

    expect($limiter->acquire($limited, $policy)->allowed())->toBeTrue();

    $denied = $limiter->acquire($limited, $policy);

    expect($denied->allowed())->toBeFalse()
        ->and($denied->rejection())->toBe(RateLimitRejection::QuotaExceeded)
        ->and($denied->retryAfter()?->value)->toBe(10)
        ->and($limiter->acquire(RemoteCallScope::of('fakturownia', 'tenant-b', 'invoices'), $policy)->allowed())->toBeTrue()
        ->and($limiter->acquire(RemoteCallScope::of('fakturownia', 'tenant-a', 'clients'), $policy)->allowed())->toBeTrue()
        ->and($limiter->acquire(RemoteCallScope::of('allegro', 'tenant-a', 'invoices'), $policy)->allowed())->toBeTrue();
});

it('clamps shared retry-after cooldown and never shortens an existing suspension', function (): void {
    $time = new ManualStoreTime;
    $limiter = new ScopedRateLimiter(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $policy = s92RatePolicy();

    $minimum = $limiter->suspend($scope, $policy, new RetryAfterSeconds(0));

    expect($minimum->suspended)->toBeTrue()
        ->and($minimum->retryAfter?->value)->toBe(2);

    $maximum = $limiter->suspend($scope, $policy, new RetryAfterSeconds(600));
    $notShortened = $limiter->suspend($scope, $policy, new RetryAfterSeconds(1));

    expect($maximum->retryAfter?->value)->toBe(30)
        ->and($notShortened->retryAfter?->value)->toBe(30)
        ->and($limiter->acquire($scope, $policy)->rejection())->toBe(RateLimitRejection::Cooldown);

    $time->advanceMilliseconds(30_000);

    expect($limiter->acquire($scope, $policy)->allowed())->toBeTrue();
});

it('pins policy and does not reset state when a caller drifts it', function (): void {
    $time = new ManualStoreTime;
    $limiter = new ScopedRateLimiter(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $original = s92RatePolicy();
    $drifted = s92RatePolicy(2);

    expect($limiter->acquire($scope, $original)->allowed())->toBeTrue();

    $drift = $limiter->acquire($scope, $drifted);

    expect($drift->allowed())->toBeFalse()
        ->and($drift->rejection())->toBe(RateLimitRejection::PolicyMismatch)
        ->and(fn () => $limiter->suspend($scope, $drifted, new RetryAfterSeconds(30)))
        ->toThrow(ResiliencePolicyMismatch::class)
        ->and($limiter->acquire($scope, $original)->rejection())->toBe(RateLimitRejection::QuotaExceeded);
});

it('fails closed for hostile state schema and unknown fields', function (string $hostileState): void {
    $time = new ManualStoreTime;
    $store = new InMemoryAtomicResilienceStateStore($time);
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $store->putRaw(ResilienceStateKey::rate($scope), $hostileState);
    $limiter = new ScopedRateLimiter($store);

    expect(fn () => $limiter->acquire($scope, s92RatePolicy()))
        ->toThrow(CorruptResilienceState::class);
})->with([
    'not json' => 'not-json',
    'unknown version' => '{"v":2}',
    'unknown field' => '{"v":1,"policy":"'.str_repeat('a', 64).'","sequence":0,"last_store_ms":1,"theoretical_arrival_ms":1,"cooldown_until_ms":0,"secret":"x"}',
]);

it('uses a monotonic store watermark when backend time moves backwards', function (): void {
    $time = new ManualStoreTime(10_000);
    $limiter = new ScopedRateLimiter(new InMemoryAtomicResilienceStateStore($time));
    $scope = RemoteCallScope::of('fakturownia', 'tenant-a', 'invoices');
    $policy = s92RatePolicy();

    $limiter->suspend($scope, $policy, new RetryAfterSeconds(10));
    $time->setMilliseconds(1_000);

    expect($limiter->status($scope)->retryAfter?->value)->toBe(10)
        ->and($limiter->acquire($scope, $policy)->rejection())->toBe(RateLimitRejection::Cooldown);
});

it('rejects a GCRA backlog horizon that can outlive state TTL and accepts the exact boundary', function (): void {
    expect(fn () => new RateLimitPolicy(1, 86_400, 10_000, 1, 30, 604_800))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RateLimitPolicy(1, 10, 3, 1, 1, 29))
        ->toThrow(InvalidArgumentException::class)
        ->and(new RateLimitPolicy(1, 10, 3, 1, 1, 30)->stateTtlSeconds)
        ->toBe(30);
});
