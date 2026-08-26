<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use InvalidArgumentException;

/** @api */
final readonly class SafeOperationTelemetryContext
{
    public function __construct(
        public ProviderKey $provider,
        public ?OperationId $operationId = null,
        public ?OperationType $operationType = null,
        public ?OperationStatus $status = null,
        public ?OperationDisposition $disposition = null,
        public ?EffectState $effectState = null,
        public ?string $reasonCode = null,
        public ?int $attemptNumber = null,
        public ?int $writerGeneration = null,
        public ?OwnerMode $ownerMode = null,
        public ?PollPurpose $pollPurpose = null,
        public ?string $projectionTarget = null,
    ) {
        if ($operationType !== null && ! $operationType->belongsTo($provider)) {
            throw new InvalidArgumentException('Telemetry operation type does not belong to its provider.');
        }

        if ($reasonCode !== null && preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Telemetry reason code is invalid.');
        }

        if ($projectionTarget !== null && preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $projectionTarget) !== 1) {
            throw new InvalidArgumentException('Telemetry projection target is invalid.');
        }

        if ($attemptNumber !== null && $attemptNumber < 1) {
            throw new InvalidArgumentException('Telemetry attempt number is invalid.');
        }

        if ($writerGeneration !== null && $writerGeneration < 1) {
            throw new InvalidArgumentException('Telemetry writer generation is invalid.');
        }
    }

    /** @return array<string, bool|int|string> */
    public function toLogContext(): array
    {
        $context = [
            'provider' => $this->provider->value,
            'operation_id' => $this->operationId?->value,
            'operation_type' => $this->operationType?->value,
            'status' => $this->status?->value,
            'disposition' => $this->disposition?->value,
            'effect_state' => $this->effectState?->value,
            'reason_code' => $this->reasonCode,
            'attempt_number' => $this->attemptNumber,
            'writer_generation' => $this->writerGeneration,
            'owner_mode' => $this->ownerMode?->value,
            'poll_purpose' => $this->pollPurpose?->value,
            'projection_target' => $this->projectionTarget,
        ];

        return array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
