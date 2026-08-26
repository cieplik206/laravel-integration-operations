<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;

/** @internal */
final readonly class StateTransition
{
    public function __construct(
        public ?OperationStatus $fromStatus,
        public ?OperationDisposition $fromDisposition,
        public ?EffectState $fromEffectState,
        public OperationStatus $toStatus,
        public OperationDisposition $toDisposition,
        public EffectState $toEffectState,
    ) {}
}
