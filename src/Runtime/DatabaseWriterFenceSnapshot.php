<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use InvalidArgumentException;

/** @internal */
final readonly class DatabaseWriterFenceSnapshot
{
    private function __construct(
        public bool $available,
        public ?int $generation,
        public ?OwnerMode $ownerMode,
        public ?string $cohortDigest,
        public ?int $cohortKeyVersion,
        public DatabaseWriterFenceAliasSet $trustedCohortAliases,
    ) {
        if ($available !== ($generation !== null && $ownerMode !== null)
            || (($cohortDigest === null) !== ($cohortKeyVersion === null))
            || (! $available && ($cohortDigest !== null || $cohortKeyVersion !== null))
            || ($cohortDigest === null && ! $trustedCohortAliases->isEmpty())
            || ($cohortDigest !== null
                && ! $trustedCohortAliases->matches((int) $cohortKeyVersion, $cohortDigest))) {
            throw new InvalidArgumentException('Writer-fence snapshot is inconsistent.');
        }
    }

    public static function unavailable(): self
    {
        return new self(false, null, null, null, null, DatabaseWriterFenceAliasSet::empty());
    }

    public static function available(
        int $generation,
        OwnerMode $ownerMode,
        ?string $cohortDigest,
        ?int $cohortKeyVersion,
        DatabaseWriterFenceAliasSet $trustedCohortAliases,
    ): self {
        return new self(
            true,
            $generation,
            $ownerMode,
            $cohortDigest,
            $cohortKeyVersion,
            $trustedCohortAliases,
        );
    }
}
