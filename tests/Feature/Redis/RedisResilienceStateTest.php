<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Resilience\CircuitBreakerPolicy;
use Cieplik206\IntegrationOperations\Resilience\CircuitRejection;
use Cieplik206\IntegrationOperations\Resilience\LaravelRedisAtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Resilience\RateLimitPolicy;
use Cieplik206\IntegrationOperations\Resilience\RateLimitRejection;
use Cieplik206\IntegrationOperations\Resilience\RemoteCallKind;
use Cieplik206\IntegrationOperations\Resilience\RemoteCallScope;
use Cieplik206\IntegrationOperations\Resilience\ScopedCircuitBreaker;
use Cieplik206\IntegrationOperations\Resilience\ScopedRateLimiter;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Assert;

/** @return array{host: string, port: int, database: int, password: string|null} */
function s92RedisConfiguration(): array
{
    $host = getenv('INTEGRATION_OPERATIONS_REDIS_HOST');
    $port = getenv('INTEGRATION_OPERATIONS_REDIS_PORT');
    $database = getenv('INTEGRATION_OPERATIONS_REDIS_DATABASE');
    $password = getenv('INTEGRATION_OPERATIONS_REDIS_PASSWORD');

    return [
        'host' => is_string($host) && $host !== '' ? $host : '127.0.0.1',
        'port' => is_string($port) && ctype_digit($port) ? (int) $port : 6379,
        'database' => is_string($database) && ctype_digit($database) ? (int) $database : 15,
        'password' => is_string($password) && $password !== '' ? $password : null,
    ];
}

function s92RedisStore(string $prefix): RedisStore
{
    if (! extension_loaded('redis')) {
        Assert::markTestSkipped('The real Redis gate requires ext-redis.');
    }

    $configuration = s92RedisConfiguration();
    $manager = new RedisManager(app(), 'phpredis', [
        'options' => [],
        'default' => [
            'host' => $configuration['host'],
            'port' => $configuration['port'],
            'database' => $configuration['database'],
            'password' => $configuration['password'],
            'timeout' => 1.0,
            'read_timeout' => 1.0,
        ],
    ]);
    $store = new RedisStore($manager, $prefix, 'default');

    try {
        if ($store->connection()->ping() !== true) {
            Assert::markTestSkipped('The real Redis gate could not confirm PING.');
        }
    } catch (Throwable) {
        Assert::markTestSkipped('The real Redis gate cannot reach its configured Redis server.');
    }

    return $store;
}

it('uses Redis TIME and Lua compare-and-swap for isolated rate state', function (): void {
    $prefix = 'integration-operations-s92-'.bin2hex(random_bytes(8)).':';
    $store = s92RedisStore($prefix);
    $adapter = new LaravelRedisAtomicResilienceStateStore($store);
    $limiter = new ScopedRateLimiter($adapter);
    $policy = new RateLimitPolicy(1, 10, 1, 1, 30, 60);
    $scope = RemoteCallScope::of('fakturownia', 'redis-a', 'invoices');
    $otherScope = RemoteCallScope::of('fakturownia', 'redis-b', 'invoices');

    try {
        expect($limiter->acquire($scope, $policy)->allowed())->toBeTrue()
            ->and($limiter->acquire($scope, $policy)->rejection())->toBe(RateLimitRejection::QuotaExceeded)
            ->and($limiter->acquire($otherScope, $policy)->allowed())->toBeTrue();
    } finally {
        $store->connection()->del(
            $prefix.ResilienceStateKey::rate($scope)->cacheKey('integration-operations:resilience:'),
            $prefix.ResilienceStateKey::rate($otherScope)->cacheKey('integration-operations:resilience:'),
        );
    }
});

it('allows exactly one half-open probe across racing Redis processes', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
        Assert::markTestSkipped('The real Redis race gate requires pcntl.');
    }

    $prefix = 'integration-operations-s92-'.bin2hex(random_bytes(8)).':';
    $store = s92RedisStore($prefix);
    $scope = RemoteCallScope::of('fakturownia', 'redis-race', 'invoices');
    $policy = new CircuitBreakerPolicy(1, 1, 2, 5, 5, 16, 60);
    $breaker = new ScopedCircuitBreaker(new LaravelRedisAtomicResilienceStateStore($store));
    $call = $breaker->acquire($scope, RemoteCallKind::Read, $policy)->permit();

    expect($call)->not->toBeNull();
    $breaker->recordFailure($call);
    usleep(1_100_000);

    $temporaryDirectory = sys_get_temp_dir().'/integration-operations-s92-'.bin2hex(random_bytes(8));

    if (! mkdir($temporaryDirectory, 0700)) {
        throw new RuntimeException('Unable to create the Redis race result directory.');
    }

    $readyKey = $prefix.'race-ready';
    $barrierKey = $prefix.'race-barrier';
    $processes = 8;
    $children = [];

    try {
        for ($process = 0; $process < $processes; $process++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Unable to fork the Redis race worker.');
            }

            if ($pid > 0) {
                $children[] = $pid;

                continue;
            }

            try {
                $childStore = s92RedisStore($prefix);
                $connection = $childStore->connection();
                $connection->incr($readyKey);
                $deadline = microtime(true) + 5;

                while ($connection->get($barrierKey) !== 'go') {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Redis race barrier timed out.');
                    }

                    usleep(1_000);
                }

                $decision = (new ScopedCircuitBreaker(
                    new LaravelRedisAtomicResilienceStateStore($childStore),
                ))->acquireProbe($scope, $policy);
                file_put_contents(
                    $temporaryDirectory.'/result-'.$process,
                    $decision->allowed() ? 'allowed' : 'denied:'.$decision->rejection()?->value,
                    LOCK_EX,
                );
            } catch (Throwable $exception) {
                file_put_contents(
                    $temporaryDirectory.'/result-'.$process,
                    'error:'.$exception::class,
                    LOCK_EX,
                );
            }

            exit(0);
        }

        $deadline = microtime(true) + 5;

        while ((int) ($store->connection()->get($readyKey) ?: 0) < $processes) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Redis race workers did not reach the barrier.');
            }

            usleep(1_000);
        }

        $store->connection()->command('set', [$barrierKey, 'go', ['ex' => 10]]);

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException('A Redis race worker exited abnormally.');
            }
        }

        $results = [];

        for ($process = 0; $process < $processes; $process++) {
            $result = file_get_contents($temporaryDirectory.'/result-'.$process);

            if (! is_string($result)) {
                throw new RuntimeException('A Redis race result is missing.');
            }

            $results[] = $result;
        }

        expect(array_filter($results, fn (string $result): bool => $result === 'allowed'))->toHaveCount(1)
            ->and(array_filter($results, fn (string $result): bool => str_starts_with($result, 'error:')))->toBeEmpty()
            ->and($breaker->acquire($scope, RemoteCallKind::Mutation, $policy)->rejection())->toBe(CircuitRejection::ProbeInProgress);
    } finally {
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }

        $store->connection()->del(
            $readyKey,
            $barrierKey,
            $prefix.ResilienceStateKey::circuit($scope)->cacheKey('integration-operations:resilience:'),
        );

        for ($process = 0; $process < $processes; $process++) {
            $path = $temporaryDirectory.'/result-'.$process;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($temporaryDirectory);
    }
});
