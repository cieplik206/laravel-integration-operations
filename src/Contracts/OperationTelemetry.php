<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Enums\OperationTelemetryEvent;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationTelemetryContext;

/** @api */
interface OperationTelemetry
{
    public function record(
        OperationTelemetryEvent $event,
        SafeOperationTelemetryContext $context,
    ): void;
}
