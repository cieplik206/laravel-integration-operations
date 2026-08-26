<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** @api */
final readonly class ReconciliationObservation
{
    public DateTimeImmutable $observedAt;

    public function __construct(
        public int $observationNumber,
        public ReconciliationResult $result,
        public string $evidenceCode,
        DateTimeImmutable $observedAt,
    ) {
        if ($observationNumber < 1) {
            throw new InvalidArgumentException('Reconciliation observation number must be positive.');
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $evidenceCode) !== 1) {
            throw new InvalidArgumentException('Reconciliation observation evidence code is invalid.');
        }

        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
