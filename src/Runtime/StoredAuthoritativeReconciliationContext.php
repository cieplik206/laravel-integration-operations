<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/** @internal */
final readonly class StoredAuthoritativeReconciliationContext implements AuthoritativeReconciliationContext
{
    public DateTimeImmutable $effectPossiblyStartedAt;

    public DateTimeImmutable $observationStartedAt;

    /** @var list<ReconciliationObservation> */
    private array $observations;

    /** @param list<ReconciliationObservation> $observations */
    public function __construct(
        private StoredOperationView $view,
        private int $observationNumber,
        DateTimeImmutable $effectPossiblyStartedAt,
        DateTimeImmutable $observationStartedAt,
        array $observations,
        private ReconciliationTrigger $trigger,
    ) {
        if ($observationNumber < 1
            || $effectPossiblyStartedAt > $observationStartedAt
            || count($observations) > 100) {
            throw new InvalidArgumentException('Stored authoritative reconciliation context is invalid.');
        }

        $previousNumber = 0;

        foreach ($observations as $observation) {
            if ($observation->observationNumber <= $previousNumber
                || $observation->observationNumber >= $observationNumber
                || $observation->observedAt > $observationStartedAt) {
                throw new InvalidArgumentException('Stored reconciliation observation history is invalid.');
            }

            $previousNumber = $observation->observationNumber;
        }

        $utc = new DateTimeZone('UTC');
        $this->effectPossiblyStartedAt = $effectPossiblyStartedAt->setTimezone($utc);
        $this->observationStartedAt = $observationStartedAt->setTimezone($utc);
        $this->observations = $observations;
    }

    public function operationId(): OperationId
    {
        return $this->view->operationId();
    }

    public function scope(): IntegrationScope
    {
        return $this->view->scope();
    }

    public function operationType(): OperationType
    {
        return $this->view->operationType();
    }

    public function context(): IntegrationContext
    {
        return $this->view->context();
    }

    public function payload(): CanonicalObject
    {
        return $this->view->payload();
    }

    public function observationNumber(): int
    {
        return $this->observationNumber;
    }

    public function effectPossiblyStartedAt(): DateTimeImmutable
    {
        return $this->effectPossiblyStartedAt;
    }

    public function observationStartedAt(): DateTimeImmutable
    {
        return $this->observationStartedAt;
    }

    public function priorObservations(): array
    {
        return $this->observations;
    }

    public function reconciliationTrigger(): ReconciliationTrigger
    {
        return $this->trigger;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Stored authoritative reconciliation contexts cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Stored authoritative reconciliation contexts cannot be cloned.');
    }
}
