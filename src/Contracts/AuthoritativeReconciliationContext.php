<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use DateTimeImmutable;

/** @api */
interface AuthoritativeReconciliationContext extends ReconciliationContext
{
    public function effectPossiblyStartedAt(): DateTimeImmutable;

    public function observationStartedAt(): DateTimeImmutable;

    /** @return list<ReconciliationObservation> */
    public function priorObservations(): array;

    public function reconciliationTrigger(): ReconciliationTrigger;
}
