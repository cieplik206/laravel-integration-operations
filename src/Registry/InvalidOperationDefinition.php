<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use DomainException;

final class InvalidOperationDefinition extends DomainException
{
    /** @param list<string> $violations */
    public static function fromViolations(array $violations): self
    {
        return new self('Invalid operation definition: '.implode('; ', $violations));
    }
}
