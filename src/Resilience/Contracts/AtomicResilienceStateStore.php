<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience\Contracts;

use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateMutation;
use Cieplik206\IntegrationOperations\Resilience\Storage\AtomicStateSnapshot;
use Cieplik206\IntegrationOperations\Resilience\Storage\ResilienceStateKey;
use Closure;

/** @api */
interface AtomicResilienceStateStore
{
    public function snapshot(ResilienceStateKey $key): AtomicStateSnapshot;

    /**
     * @template TResult
     *
     * @param  Closure(AtomicStateSnapshot): AtomicStateMutation<TResult>  $transition
     * @return TResult
     */
    public function transition(ResilienceStateKey $key, Closure $transition): mixed;
}
