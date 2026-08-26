<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience\Storage;

/** @internal */
final readonly class AtomicStateSnapshot
{
    public function __construct(
        public ?string $encodedState,
        public StoreInstant $storeTime,
        public string $revision,
    ) {}

    public static function from(?string $encodedState, StoreInstant $storeTime): self
    {
        return new self(
            $encodedState,
            $storeTime,
            hash('sha256', $encodedState === null ? "\0" : "\1".$encodedState),
        );
    }
}
