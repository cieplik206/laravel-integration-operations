<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\PendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Runtime\ConfiguredIntegrationScopes;
use Cieplik206\IntegrationOperations\Runtime\KernelHeartbeat;
use Cieplik206\IntegrationOperations\ValueObjects\DispatchBatch;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryBatch;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryCursor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Config\Repository;

it('shares each provider dispatch budget fairly without starving a configured connection', function (): void {
    $config = new Repository([
        'integration-operations' => [
            'queues' => [
                'claim_batch_per_provider' => 5,
                'claim_batch_per_connection' => 3,
            ],
            'scheduler' => [
                'recovery_scan_limit' => 20,
                'scopes' => [
                    ['provider' => 'fakturownia', 'connection' => 'primary'],
                    ['provider' => 'fakturownia', 'connection' => 'secondary'],
                    ['provider' => 'fakturownia', 'connection' => 'tertiary'],
                    ['provider' => 'allegro', 'connection' => 'primary'],
                ],
            ],
        ],
    ]);
    $dispatcher = new FairnessRecordingDispatcher;
    $leases = new FairnessRecordingLeaseManager;

    $report = (new KernelHeartbeat(
        new ConfiguredIntegrationScopes($config),
        $dispatcher,
        $leases,
        $config,
    ))->run();

    expect($dispatcher->calls)->toBe([
        ['scope' => 'fakturownia:primary', 'limit' => 2],
        ['scope' => 'fakturownia:secondary', 'limit' => 2],
        ['scope' => 'fakturownia:tertiary', 'limit' => 1],
        ['scope' => 'allegro:primary', 'limit' => 3],
    ])->and($leases->calls)->toBe([
        ['scope' => 'fakturownia:primary', 'limit' => 3, 'scan_limit' => 20],
        ['scope' => 'fakturownia:secondary', 'limit' => 3, 'scan_limit' => 20],
        ['scope' => 'fakturownia:tertiary', 'limit' => 3, 'scan_limit' => 20],
        ['scope' => 'allegro:primary', 'limit' => 3, 'scan_limit' => 20],
    ])->and($report->dispatchBatches)->toHaveCount(4)
        ->and($report->dispatched())->toBe(0);
});

it('fails closed when a provider budget cannot serve every configured connection', function (): void {
    $config = new Repository([
        'integration-operations' => [
            'queues' => [
                'claim_batch_per_provider' => 2,
                'claim_batch_per_connection' => 25,
            ],
            'scheduler' => [
                'recovery_scan_limit' => 20,
                'scopes' => [
                    ['provider' => 'fakturownia', 'connection' => 'primary'],
                    ['provider' => 'fakturownia', 'connection' => 'secondary'],
                    ['provider' => 'fakturownia', 'connection' => 'tertiary'],
                ],
            ],
        ],
    ]);
    $dispatcher = new FairnessRecordingDispatcher;
    $leases = new FairnessRecordingLeaseManager;
    $heartbeat = new KernelHeartbeat(
        new ConfiguredIntegrationScopes($config),
        $dispatcher,
        $leases,
        $config,
    );

    expect(fn () => $heartbeat->run())
        ->toThrow(
            InvalidArgumentException::class,
            'Integration operation provider dispatch budget cannot serve every configured connection.',
        )->and($dispatcher->calls)->toBe([])
        ->and($leases->calls)->toBe([]);
});

final class FairnessRecordingDispatcher implements PendingOperationDispatcher
{
    /** @var list<array{scope: string, limit: int}> */
    public array $calls = [];

    public function dispatch(IntegrationScope $scope, int $limit): DispatchBatch
    {
        $this->calls[] = [
            'scope' => "{$scope->provider->value}:{$scope->connection->value}",
            'limit' => $limit,
        ];

        return new DispatchBatch($scope, 0, 0, []);
    }
}

final class FairnessRecordingLeaseManager implements OperationLeaseManager
{
    /** @var list<array{scope: string, limit: int, scan_limit: int}> */
    public array $calls = [];

    public function claim(OperationId $operationId, string $owner): ?LeaseClaim
    {
        return null;
    }

    public function heartbeat(LeaseClaim $claim): ?LeaseClaim
    {
        return null;
    }

    public function recoverExpired(
        IntegrationScope $scope,
        int $limit = 100,
        int $scanLimit = 500,
        ?LeaseRecoveryCursor $after = null,
    ): LeaseRecoveryBatch {
        $this->calls[] = [
            'scope' => "{$scope->provider->value}:{$scope->connection->value}",
            'limit' => $limit,
            'scan_limit' => $scanLimit,
        ];

        return new LeaseRecoveryBatch(0, 0, 0, 0, 0, null, true);
    }
}
