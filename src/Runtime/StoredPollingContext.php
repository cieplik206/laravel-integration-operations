<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\PollingContext;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** @internal */
final readonly class StoredPollingContext implements PollingContext
{
    public DateTimeImmutable $startedAt;

    public DateTimeImmutable $deadlineAt;

    public function __construct(
        private StoredOperationView $operation,
        private PollPurpose $purpose,
        private int $attemptNumber,
        string $startedAt,
        string $deadlineAt,
    ) {
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('Poll attempt number must be positive.');
        }

        try {
            $timezone = new DateTimeZone('UTC');
            $this->startedAt = (new DateTimeImmutable($startedAt))->setTimezone($timezone);
            $this->deadlineAt = (new DateTimeImmutable($deadlineAt))->setTimezone($timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException('Poll timestamps are invalid.');
        }

        if ($this->startedAt >= $this->deadlineAt) {
            throw new InvalidArgumentException('Poll deadline must be after its start.');
        }
    }

    public function operationId(): OperationId
    {
        return $this->operation->operationId();
    }

    public function scope(): IntegrationScope
    {
        return $this->operation->scope();
    }

    public function operationType(): OperationType
    {
        return $this->operation->operationType();
    }

    public function context(): IntegrationContext
    {
        return $this->operation->context();
    }

    public function payload(): CanonicalObject
    {
        return $this->operation->payload();
    }

    public function pollPurpose(): PollPurpose
    {
        return $this->purpose;
    }

    public function pollAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function pollStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function pollDeadlineAt(): DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Stored polling contexts cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Stored polling contexts cannot be cloned.');
    }
}
