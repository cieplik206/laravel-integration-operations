<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use InvalidArgumentException;

/** @api */
final readonly class ManagedMutationIdentityContract
{
    private const int MaximumSemanticSlots = 64;

    /** @var list<string> */
    public array $semanticSlots;

    /** @param list<string> $semanticSlots */
    public function __construct(
        public string $resourceType,
        public string $localReferenceType,
        array $semanticSlots,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $resourceType) !== 1) {
            throw new InvalidArgumentException('Managed mutation resource type is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $localReferenceType) !== 1) {
            throw new InvalidArgumentException('Managed mutation local reference type is invalid.');
        }

        $slots = ImmutableValueSanitizer::stringList($semanticSlots, 'Managed mutation semantic slots');

        if ($slots === []
            || count($slots) > self::MaximumSemanticSlots
            || count(array_unique($slots, SORT_STRING)) !== count($slots)) {
            throw new InvalidArgumentException('Managed mutation semantic slots must be a bounded unique non-empty list.');
        }

        foreach ($slots as $slot) {
            if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $slot) !== 1) {
                throw new InvalidArgumentException('Managed mutation semantic slot is invalid.');
            }
        }

        $this->semanticSlots = $slots;
    }

    public function allows(IntentIdentity $identity): bool
    {
        return $this->allowsPersisted(
            $identity->resourceType,
            $identity->semanticSlot,
            $identity->localReference?->type,
        );
    }

    public function allowsPersisted(string $resourceType, string $semanticSlot, ?string $localReferenceType): bool
    {
        return $resourceType === $this->resourceType
            && $localReferenceType === $this->localReferenceType
            && in_array($semanticSlot, $this->semanticSlots, true);
    }
}
