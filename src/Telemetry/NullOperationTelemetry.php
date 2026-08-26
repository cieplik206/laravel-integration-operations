<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Telemetry;

use Cieplik206\IntegrationOperations\Contracts\OperationTelemetry;
use Cieplik206\IntegrationOperations\Enums\OperationTelemetryEvent;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationTelemetryContext;

/** @internal */
final readonly class NullOperationTelemetry implements OperationTelemetry
{
    public function record(
        OperationTelemetryEvent $event,
        SafeOperationTelemetryContext $context,
    ): void {}
}
