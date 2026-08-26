<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience\Storage;

use InvalidArgumentException;

/**
 * @template TResult
 *
 * @internal
 */
final readonly class AtomicStateMutation
{
    private const Delete = 'delete';

    private const Preserve = 'preserve';

    private const Put = 'put';

    /**
     * @param  TResult  $result
     */
    private function __construct(
        public string $mode,
        public ?string $encodedState,
        public int $ttlMilliseconds,
        public mixed $result,
    ) {
        if (! in_array($mode, [self::Delete, self::Preserve, self::Put], true)
            || ($mode === self::Put && ($encodedState === null || $encodedState === ''))
            || ($mode !== self::Put && $encodedState !== null)
            || ($mode === self::Put && ($ttlMilliseconds < 1_000 || $ttlMilliseconds > 604_800_000))
            || ($mode !== self::Put && $ttlMilliseconds !== 0)) {
            throw new InvalidArgumentException('Atomic state mutation is invalid.');
        }
    }

    /**
     * @template TPutResult
     *
     * @param  TPutResult  $result
     * @return self<TPutResult>
     */
    public static function put(string $encodedState, int $ttlMilliseconds, mixed $result): self
    {
        return new self(self::Put, $encodedState, $ttlMilliseconds, $result);
    }

    /**
     * @template TPreserveResult
     *
     * @param  TPreserveResult  $result
     * @return self<TPreserveResult>
     */
    public static function preserve(mixed $result): self
    {
        return new self(self::Preserve, null, 0, $result);
    }

    /**
     * @template TDeleteResult
     *
     * @param  TDeleteResult  $result
     * @return self<TDeleteResult>
     */
    public static function delete(mixed $result): self
    {
        return new self(self::Delete, null, 0, $result);
    }

    public function writesState(): bool
    {
        return $this->mode === self::Put;
    }

    public function deletesState(): bool
    {
        return $this->mode === self::Delete;
    }

    public function preservesState(): bool
    {
        return $this->mode === self::Preserve;
    }
}
