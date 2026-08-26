<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Resilience;

use Cieplik206\IntegrationOperations\Resilience\Contracts\AtomicResilienceStateStore;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateMutation;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateSnapshot;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Closure;

/** @api */
final class InMemoryAtomicResilienceStateStore implements AtomicResilienceStateStore
{
    /** @var array<string, array{state: string, expires_at: int}> */
    private array $states = [];

    public function __construct(private readonly ManualStoreTime $time) {}

    public function snapshot(ResilienceStateKey $key): AtomicStateSnapshot
    {
        $cacheKey = $key->cacheKey('testing:');
        $stored = $this->states[$cacheKey] ?? null;

        if ($stored !== null && $stored['expires_at'] <= $this->time->now()->milliseconds) {
            unset($this->states[$cacheKey]);
            $stored = null;
        }

        return AtomicStateSnapshot::from($stored['state'] ?? null, $this->time->now());
    }

    /**
     * @template TResult
     *
     * @param  Closure(AtomicStateSnapshot): AtomicStateMutation<TResult>  $transition
     * @return TResult
     */
    public function transition(ResilienceStateKey $key, Closure $transition): mixed
    {
        $snapshot = $this->snapshot($key);
        $mutation = $transition($snapshot);
        $cacheKey = $key->cacheKey('testing:');

        if ($mutation->writesState()) {
            $this->states[$cacheKey] = [
                'state' => (string) $mutation->encodedState,
                'expires_at' => $snapshot->storeTime->milliseconds + $mutation->ttlMilliseconds,
            ];
        } elseif ($mutation->deletesState()) {
            unset($this->states[$cacheKey]);
        }

        return $mutation->result;
    }

    public function putRaw(ResilienceStateKey $key, string $encodedState, int $ttlMilliseconds = 60_000): void
    {
        $this->states[$key->cacheKey('testing:')] = [
            'state' => $encodedState,
            'expires_at' => $this->time->now()->milliseconds + $ttlMilliseconds,
        ];
    }
}
