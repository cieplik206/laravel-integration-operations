<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use InvalidArgumentException;

/** @api */
final readonly class ClassifiedFailure
{
    public function __construct(
        public FailureDisposition $disposition,
        public SafeOperationFailure $safeFailure,
        public ReconciliationTrigger $reconciliationTrigger = ReconciliationTrigger::Unknown,
        public ?RetryAfterSeconds $retryAfter = null,
    ) {
        if ($disposition !== FailureDisposition::Uncertain
            && $reconciliationTrigger !== ReconciliationTrigger::Unknown) {
            throw new InvalidArgumentException('Only an uncertain failure may declare reconciliation provenance.');
        }

        if ($retryAfter !== null
            && ! in_array($disposition, [FailureDisposition::RetryableRead, FailureDisposition::RequestNotStarted], true)) {
            throw new InvalidArgumentException('Retry-after is only valid for a safely retryable failure.');
        }

    }
}
