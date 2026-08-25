<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance;

use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use DomainException;

final class ProviderConformanceFailed extends DomainException
{
    /** @var list<string> */
    public readonly array $violations;

    /** @param list<string> $violations */
    public function __construct(array $violations)
    {
        $this->violations = ImmutableValueSanitizer::stringList(
            $violations,
            'Provider conformance failure violations',
        );

        parent::__construct('Provider conformance failed: '.implode('; ', $this->violations));
    }
}
