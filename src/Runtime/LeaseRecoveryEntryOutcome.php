<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Enums\LeaseRecoveryDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryIncident;
use InvalidArgumentException;

/** @internal */
final readonly class LeaseRecoveryEntryOutcome
{
    public function __construct(
        public LeaseRecoveryDisposition $disposition,
        public ?LeaseRecoveryIncident $incident = null,
    ) {
        $valid = match ($disposition) {
            LeaseRecoveryDisposition::Recovered,
            LeaseRecoveryDisposition::Skipped => $incident === null,
            LeaseRecoveryDisposition::Quarantined => $incident?->quarantined === true,
            LeaseRecoveryDisposition::Deferred => $incident?->quarantined === false,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Lease recovery entry outcome is inconsistent.');
        }
    }
}
