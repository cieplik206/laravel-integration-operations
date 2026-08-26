<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OperationTelemetryEvent;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use Cieplik206\IntegrationOperations\Telemetry\PsrOperationTelemetry;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationTelemetryContext;
use Psr\Log\AbstractLogger;
use Stringable;

final class CapturingOperationLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

final class ThrowingOperationLogger extends AbstractLogger
{
    /** @param array<string, mixed> $context */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        throw new RuntimeException('Exporter unavailable with token=must-not-escape.');
    }
}

it('emits only the bounded operation telemetry allowlist', function (): void {
    $logger = new CapturingOperationLogger;
    $telemetry = new PsrOperationTelemetry($logger);
    $telemetry->record(
        OperationTelemetryEvent::Terminalized,
        new SafeOperationTelemetryContext(
            provider: new ProviderKey('fakturownia'),
            operationId: new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
            operationType: new OperationType('fakturownia.invoice.issue'),
            status: OperationStatus::Failed,
            disposition: OperationDisposition::Failed,
            effectState: EffectState::NotStarted,
            reasonCode: 'provider_rejected',
            attemptNumber: 1,
            writerGeneration: 2,
            ownerMode: OwnerMode::CanaryWrite,
            pollPurpose: PollPurpose::Preflight,
            projectionTarget: 'fakturownia.invoice',
        ),
    );

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0])->toBe([
            'level' => 'info',
            'message' => 'integration_operation.terminalized',
            'context' => [
                'event' => 'terminalized',
                'provider' => 'fakturownia',
                'operation_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'operation_type' => 'fakturownia.invoice.issue',
                'status' => 'failed',
                'disposition' => 'failed',
                'effect_state' => 'not_started',
                'reason_code' => 'provider_rejected',
                'attempt_number' => 1,
                'writer_generation' => 2,
                'owner_mode' => 'canary_write',
                'poll_purpose' => 'preflight',
                'projection_target' => 'fakturownia.invoice',
            ],
        ])
        ->and(array_keys($logger->records[0]['context']))->not->toContain(
            'payload',
            'token',
            'nip',
            'email',
            'pdf',
            'xml',
            'http',
            'connection_key',
        );
});

it('uses warning severity for manual review and fence denial', function (OperationTelemetryEvent $event): void {
    $logger = new CapturingOperationLogger;
    (new PsrOperationTelemetry($logger))->record(
        $event,
        new SafeOperationTelemetryContext(new ProviderKey('fakturownia')),
    );

    expect($logger->records[0]['level'])->toBe('warning');
})->with([
    OperationTelemetryEvent::ManualReview,
    OperationTelemetryEvent::FenceDenied,
]);

it('rejects unsafe free-form telemetry fields before logging', function (): void {
    expect(fn () => new SafeOperationTelemetryContext(
        new ProviderKey('fakturownia'),
        reasonCode: 'token=secret',
    ))->toThrow(InvalidArgumentException::class, 'Telemetry reason code is invalid.');
});

it('keeps logger and exporter failures outside the correctness boundary', function (): void {
    expect(fn () => (new PsrOperationTelemetry(new ThrowingOperationLogger))->record(
        OperationTelemetryEvent::Accepted,
        new SafeOperationTelemetryContext(new ProviderKey('fakturownia')),
    ))->not->toThrow(RuntimeException::class);
});
