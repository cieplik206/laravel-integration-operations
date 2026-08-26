<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Jobs\ProcessIntegrationOperation;
use Cieplik206\IntegrationOperations\Registry\ConfigWriterFenceResolver;
use Cieplik206\IntegrationOperations\Runtime\LeaseTimingPolicy;
use Cieplik206\IntegrationOperations\Runtime\QueueDurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

it('resolves an exact writer fence record without trusting command input', function (): void {
    $resolver = new ConfigWriterFenceResolver(new Repository([
        'integration-operations' => [
            'writer_fences' => [[
                'provider' => 'fixture_catalog',
                'connection' => 'tenant:1',
                'operation_type' => 'fixture_catalog.record.fetch',
                'generation' => 7,
                'owner_mode' => 'shadow_read',
                'cohort' => 'cohort-a',
            ]],
        ],
    ]));

    $fence = $resolver->current(
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        new OperationType('fixture_catalog.record.fetch'),
    );

    expect($fence)->not->toBeNull()
        ->and($fence?->generation)->toBe(7)
        ->and($fence?->ownerMode)->toBe(OwnerMode::ShadowRead)
        ->and($fence?->cohort())->toBe('cohort-a')
        ->and($resolver->current(
            IntegrationScope::of('fixture_catalog', 'tenant:2'),
            new OperationType('fixture_catalog.record.fetch'),
        ))->toBeNull();
});

it('fails closed for ambiguous or malformed writer fence records', function (array $records): void {
    $resolver = new ConfigWriterFenceResolver(new Repository([
        'integration-operations' => ['writer_fences' => $records],
    ]));

    expect(fn () => $resolver->current(
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        new OperationType('fixture_catalog.record.fetch'),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'ambiguous duplicate' => [[
        [
            'provider' => 'fixture_catalog',
            'connection' => 'tenant:1',
            'operation_type' => 'fixture_catalog.record.fetch',
            'generation' => 1,
            'owner_mode' => 'on',
            'cohort' => null,
        ],
        [
            'provider' => 'fixture_catalog',
            'connection' => 'tenant:1',
            'operation_type' => 'fixture_catalog.record.fetch',
            'generation' => 2,
            'owner_mode' => 'on',
            'cohort' => null,
        ],
    ]],
    'unknown owner mode' => [[[
        'provider' => 'fixture_catalog',
        'connection' => 'tenant:1',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 1,
        'owner_mode' => 'untrusted',
        'cohort' => null,
    ]]],
    'canary without cohort' => [[[
        'provider' => 'fixture_catalog',
        'connection' => 'tenant:1',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 1,
        'owner_mode' => 'canary_write',
        'cohort' => null,
    ]]],
    'extra field' => [[[
        'provider' => 'fixture_catalog',
        'connection' => 'tenant:1',
        'operation_type' => 'fixture_catalog.record.fetch',
        'generation' => 1,
        'owner_mode' => 'on',
        'cohort' => null,
        'force' => true,
    ]]],
]);

it('never exposes a writer cohort through diagnostic or serialization surfaces', function (): void {
    $cohort = 'sensitive-canary-cohort';
    $fence = new WriterFence(7, OwnerMode::CanaryWrite, $cohort);

    ob_start();
    var_dump($fence);
    $dump = ob_get_clean();
    $export = var_export($fence, true);
    $json = json_encode($fence, JSON_THROW_ON_ERROR);

    expect($dump)->toBeString()
        ->not->toContain($cohort)
        ->and($export)->not->toContain($cohort)
        ->and($json)->not->toContain($cohort)
        ->and(fn () => serialize($fence))->toThrow(LogicException::class)
        ->and(fn () => clone $fence)->toThrow(LogicException::class);
});

it('requires a deterministic cohort in the public canary writer fence value object', function (): void {
    expect(fn () => new WriterFence(1, OwnerMode::CanaryWrite))->toThrow(InvalidArgumentException::class);
});

it('dispatches an encrypted final job containing only a normalized operation ID through an allowlisted route', function (): void {
    $dispatched = null;
    $bus = Mockery::mock(Dispatcher::class);

    if (! $bus instanceof Dispatcher) {
        throw new LogicException('Unable to create the test bus dispatcher.');
    }

    $bus->shouldReceive('dispatch')->once()->andReturnUsing(function (mixed $job) use (&$dispatched): mixed {
        $dispatched = $job;

        return $job;
    });
    $notifier = new QueueDurableAcceptanceNotifier($bus, new Repository([
        'integration-operations' => [
            'queues' => [
                'connection' => 'redis',
                'routes' => [
                    'default' => 'integration-operations',
                    'fixture_catalog' => 'integration-operations-catalog',
                ],
                'allowlist' => ['integration-operations', 'integration-operations-catalog'],
            ],
        ],
    ]));
    $operationId = new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $receipt = new OperationReceipt(
        $operationId,
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        new OperationType('fixture_catalog.record.fetch'),
        false,
    );

    $notifier->notify($receipt);

    expect($dispatched)->toBeInstanceOf(ProcessIntegrationOperation::class);

    if (! $dispatched instanceof ProcessIntegrationOperation) {
        throw new LogicException('The queue notifier did not dispatch the final operation job.');
    }

    $interfaces = class_implements(ProcessIntegrationOperation::class);

    expect($interfaces)->toBeArray()
        ->toHaveKeys([ShouldQueue::class, ShouldBeEncrypted::class])
        ->and($dispatched->operationId)->toBe($operationId->value)
        ->and($dispatched->queue)->toBe('integration-operations-catalog')
        ->and($dispatched->connection)->toBe('redis')
        ->and(array_keys(get_object_vars($dispatched)))->not->toContain('scope', 'payload', 'handler');
});

it('normalizes the operation ID at final-job construction', function (): void {
    $job = new ProcessIntegrationOperation(strtolower('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    expect($job->operationId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV');
});

it('rejects queue routes outside the explicit allowlist', function (): void {
    $bus = Mockery::mock(Dispatcher::class);

    if (! $bus instanceof Dispatcher) {
        throw new LogicException('Unable to create the test bus dispatcher.');
    }

    $bus->shouldNotReceive('dispatch');
    $notifier = new QueueDurableAcceptanceNotifier($bus, new Repository([
        'integration-operations' => [
            'queues' => [
                'connection' => null,
                'routes' => ['default' => 'untrusted-route'],
                'allowlist' => ['integration-operations'],
            ],
        ],
    ]));

    expect(fn () => $notifier->notify(new OperationReceipt(
        new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        new OperationType('fixture_catalog.record.fetch'),
        false,
    )))->toThrow(InvalidArgumentException::class);
});

it('accepts only lease timing with a heartbeat inside the lease and a full remote-call budget', function (): void {
    $policy = new LeaseTimingPolicy(new Repository([
        'integration-operations' => [
            'leases' => [
                'seconds' => 120,
                'heartbeat_seconds' => 30,
                'connect_timeout_seconds' => 10,
                'request_timeout_seconds' => 60,
                'safety_margin_seconds' => 15,
            ],
        ],
    ]));

    expect($policy->leaseSeconds())->toBe(120)
        ->and($policy->heartbeatSeconds())->toBe(30)
        ->and($policy->remoteCallBudgetSeconds())->toBe(85);
});

it('rejects unsafe lease timing policies', function (array $leases): void {
    $policy = new LeaseTimingPolicy(new Repository([
        'integration-operations' => ['leases' => $leases],
    ]));

    expect(fn (): int => $policy->leaseSeconds())->toThrow(InvalidArgumentException::class);
})->with([
    'heartbeat equal to lease' => [[
        'seconds' => 120,
        'heartbeat_seconds' => 120,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]],
    'lease cannot cover transport budget' => [[
        'seconds' => 85,
        'heartbeat_seconds' => 20,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]],
    'missing timeout' => [[
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ]],
]);
