<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\Resilience\Contracts\AtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Resilience\Exceptions\AtomicResilienceStateStoreUnavailable;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateMutation;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateSnapshot;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Cieplik206\IntegrationOperations\Resilience\Storage\StoreInstant;
use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use InvalidArgumentException;
use Throwable;

/** @api */
final readonly class LaravelRedisAtomicResilienceStateStore implements AtomicResilienceStateStore
{
    private const MaximumStateBytes = 65_536;

    private const SnapshotLua = <<<'LUA'
local now = redis.call('TIME')
local value = redis.call('GET', KEYS[1])

if value == false then
    return { now[1], now[2], 0, '' }
end

return { now[1], now[2], 1, value }
LUA;

    private const CompareAndSwapLua = <<<'LUA'
local current = redis.call('GET', KEYS[1])
local expected_exists = ARGV[1]
local matches = false

if expected_exists == '0' then
    matches = current == false
else
    matches = current ~= false and current == ARGV[2]
end

local now = redis.call('TIME')

if not matches then
    return { 0, now[1], now[2] }
end

if ARGV[3] == 'delete' then
    redis.call('DEL', KEYS[1])
else
    redis.call('SET', KEYS[1], ARGV[4], 'PX', ARGV[5])
end

return { 1, now[1], now[2] }
LUA;

    public function __construct(
        private RedisStore $store,
        private string $prefix = 'integration-operations:resilience:',
        private int $maximumAttempts = 8,
    ) {
        $storePrefix = $store->getPrefix();

        if (preg_match('/^[a-z0-9:_-]{1,128}$/D', $prefix) !== 1
            || preg_match('/^[a-zA-Z0-9:_.-]{0,128}$/D', $storePrefix) !== 1
            || $maximumAttempts < 1 || $maximumAttempts > 64) {
            throw new InvalidArgumentException('Redis resilience store configuration is invalid.');
        }
    }

    public function snapshot(ResilienceStateKey $key): AtomicStateSnapshot
    {
        try {
            $result = $this->evaluate(
                self::SnapshotLua,
                $this->qualifiedKey($key),
            );

            return $this->parseSnapshot($result);
        } catch (AtomicResilienceStateStoreUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AtomicResilienceStateStoreUnavailable(
                'Atomic resilience state snapshot is unavailable.',
                previous: $exception,
            );
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(AtomicStateSnapshot): AtomicStateMutation<TResult>  $transition
     * @return TResult
     */
    public function transition(ResilienceStateKey $key, Closure $transition): mixed
    {
        for ($attempt = 1; $attempt <= $this->maximumAttempts; $attempt++) {
            $snapshot = $this->snapshot($key);
            $mutation = $transition($snapshot);

            if ($mutation->preservesState()) {
                return $mutation->result;
            }

            if ($mutation->encodedState !== null && strlen($mutation->encodedState) > self::MaximumStateBytes) {
                throw new AtomicResilienceStateStoreUnavailable('Atomic resilience state exceeds its safe size.');
            }

            if ($this->compareAndSwap($key, $snapshot, $mutation)) {
                return $mutation->result;
            }
        }

        throw new AtomicResilienceStateStoreUnavailable('Atomic resilience state transition contention is exhausted.');
    }

    private function qualifiedKey(ResilienceStateKey $key): string
    {
        return $this->store->getPrefix().$key->cacheKey($this->prefix);
    }

    private function parseSnapshot(mixed $result): AtomicStateSnapshot
    {
        if (! is_array($result) || count($result) !== 4) {
            throw new AtomicResilienceStateStoreUnavailable('Redis returned an invalid state snapshot.');
        }

        $seconds = $this->boundedInteger($result[0] ?? null, 0, PHP_INT_MAX);
        $microseconds = $this->boundedInteger($result[1] ?? null, 0, 999_999);
        $exists = $this->boundedInteger($result[2] ?? null, 0, 1);
        $encodedState = $result[3] ?? null;

        if (! is_string($encodedState)
            || ($exists === 0 && $encodedState !== '')
            || ($exists === 1 && ($encodedState === '' || strlen($encodedState) > self::MaximumStateBytes))
            || $seconds > intdiv(PHP_INT_MAX - intdiv($microseconds, 1_000), 1_000)) {
            throw new AtomicResilienceStateStoreUnavailable('Redis returned an invalid state snapshot.');
        }

        return AtomicStateSnapshot::from(
            $exists === 1 ? $encodedState : null,
            new StoreInstant(($seconds * 1_000) + intdiv($microseconds, 1_000)),
        );
    }

    /** @param AtomicStateMutation<mixed> $mutation */
    private function compareAndSwap(
        ResilienceStateKey $key,
        AtomicStateSnapshot $snapshot,
        AtomicStateMutation $mutation,
    ): bool {
        try {
            $result = $this->evaluate(
                self::CompareAndSwapLua,
                $this->qualifiedKey($key),
                $snapshot->encodedState === null ? '0' : '1',
                $snapshot->encodedState ?? '',
                $mutation->deletesState() ? 'delete' : 'put',
                $mutation->encodedState ?? '',
                (string) $mutation->ttlMilliseconds,
            );
        } catch (Throwable $exception) {
            throw new AtomicResilienceStateStoreUnavailable(
                'Atomic resilience state commit is unavailable.',
                previous: $exception,
            );
        }

        if (! is_array($result) || count($result) !== 3) {
            throw new AtomicResilienceStateStoreUnavailable('Redis returned an invalid compare-and-swap result.');
        }

        $matched = $this->boundedInteger($result[0] ?? null, 0, 1);
        $this->boundedInteger($result[1] ?? null, 0, PHP_INT_MAX);
        $this->boundedInteger($result[2] ?? null, 0, 999_999);

        return $matched === 1;
    }

    private function evaluate(string $script, string $key, string ...$arguments): mixed
    {
        $connection = $this->store->connection();

        if ($connection instanceof PhpRedisConnection) {
            return $connection->command('eval', [
                $script,
                [$key, ...$arguments],
                1,
            ]);
        }

        if ($connection instanceof PredisConnection) {
            return $connection->command('eval', [
                $script,
                1,
                $key,
                ...$arguments,
            ]);
        }

        throw new AtomicResilienceStateStoreUnavailable('Redis connection type is unsupported.');
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            if (! is_int($integer)) {
                throw new AtomicResilienceStateStoreUnavailable('Redis returned an invalid integer.');
            }
        } else {
            throw new AtomicResilienceStateStoreUnavailable('Redis returned an invalid integer.');
        }

        if ($integer < $minimum || $integer > $maximum) {
            throw new AtomicResilienceStateStoreUnavailable('Redis returned an out-of-range integer.');
        }

        return $integer;
    }
}
