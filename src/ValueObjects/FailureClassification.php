<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\FailureDisposition;

/** @api */
final readonly class FailureClassification
{
    public function __construct(
        public FailureDisposition $disposition,
        public SafeOperationFailure $safeFailure,
    ) {}
}
