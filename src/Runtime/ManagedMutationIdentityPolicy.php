<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Exceptions\LocalReferenceRequired;
use Cieplik206\IntegrationOperations\Exceptions\ManagedMutationIdentityRejected;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;

/** @internal */
final readonly class ManagedMutationIdentityPolicy
{
    public function assertSatisfiedBy(OperationDefinition $definition, IntentIdentity $identity): void
    {
        if ($definition->maximumRemoteWrites === 1 && $identity->localReference === null) {
            throw new LocalReferenceRequired;
        }

        if ($definition->maximumRemoteWrites === 1
            && ($definition->managedMutationIdentity === null
                || ! $definition->managedMutationIdentity->allows($identity))) {
            throw new ManagedMutationIdentityRejected;
        }
    }
}
