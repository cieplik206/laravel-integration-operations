<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Resilience\CircuitBreakerPolicy;
use Cieplik206\IntegrationOperations\Resilience\CircuitCallPermit;
use Cieplik206\IntegrationOperations\Resilience\HalfOpenProbePermit;
use Cieplik206\IntegrationOperations\Resilience\LaravelRedisAtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Resilience\RemoteCallKind;
use Cieplik206\IntegrationOperations\Resilience\RemoteCallScope;
use Cieplik206\IntegrationOperations\Resilience\SafeProbeObservation;
use Cieplik206\IntegrationOperations\Resilience\ScopedCircuitBreaker;
use Cieplik206\IntegrationOperations\Testing\Resilience\InMemoryAtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Testing\Resilience\ManualStoreTime;
use Illuminate\Cache\RedisStore;

it('redacts scope and permit capabilities and rejects native serialization or cloning', function (): void {
    $scope = RemoteCallScope::of('fakturownia', 'customer-42.example.test', 'invoices');
    $breaker = new ScopedCircuitBreaker(new InMemoryAtomicResilienceStateStore(new ManualStoreTime));
    $policy = new CircuitBreakerPolicy(1, 1, 2, 5, 5, 2, 60);
    $permit = $breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->permit();

    expect($permit)->toBeInstanceOf(CircuitCallPermit::class)
        ->and(print_r($scope, true))->not->toContain('customer-42.example.test')
        ->and(fn () => serialize($scope))->toThrow(LogicException::class)
        ->and(fn () => clone $scope)->toThrow(LogicException::class)
        ->and(fn () => serialize($permit))->toThrow(LogicException::class)
        ->and(fn () => clone $permit)->toThrow(LogicException::class);
});

it('rejects forged native unserialize envelopes for capabilities', function (string $class): void {
    $payload = 'O:'.strlen($class).':"'.$class.'":0:{}';

    expect(fn () => unserialize($payload))->toThrow(LogicException::class);
})->with([
    CircuitCallPermit::class,
    HalfOpenProbePermit::class,
    SafeProbeObservation::class,
    RemoteCallScope::class,
]);

it('keeps the safe observation issuer sealed until the package transport bind exists', function (): void {
    $reflection = new ReflectionClass(SafeProbeObservation::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->getConstructor()?->isPrivate())->toBeTrue()
        ->and($reflection->hasMethod('success'))->toBeFalse()
        ->and($reflection->hasMethod('failure'))->toBeFalse();
});

it('rejects Redis prefixes that can inject another cluster hash tag or unbounded key material', function (string $storePrefix, string $adapterPrefix): void {
    $reflection = new ReflectionClass(RedisStore::class);
    $store = $reflection->newInstanceWithoutConstructor();
    $prefix = $reflection->getProperty('prefix');
    $prefix->setValue($store, $storePrefix);

    expect(fn () => new LaravelRedisAtomicResilienceStateStore($store, $adapterPrefix))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'store hash-tag injection' => ['tenant:{shared}:', 'integration-operations:resilience:'],
    'adapter hash-tag injection' => ['', 'integration-operations:{shared}:'],
    'unbounded adapter prefix' => ['', str_repeat('a', 129)],
]);
