<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Telemetry;

use Cieplik206\IntegrationOperations\Contracts\OperationTelemetry;
use Cieplik206\IntegrationOperations\Enums\OperationTelemetryEvent;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationTelemetryContext;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/** @internal */
final readonly class PsrOperationTelemetry implements OperationTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    public function record(
        OperationTelemetryEvent $event,
        SafeOperationTelemetryContext $context,
    ): void {
        try {
            $this->logger->log(
                $this->level($event),
                "integration_operation.{$event->value}",
                ['event' => $event->value, ...$context->toLogContext()],
            );
        } catch (Throwable) {
        }
    }

    private function level(OperationTelemetryEvent $event): string
    {
        return match ($event) {
            OperationTelemetryEvent::FenceDenied,
            OperationTelemetryEvent::ManualReview => LogLevel::WARNING,
            default => LogLevel::INFO,
        };
    }
}
