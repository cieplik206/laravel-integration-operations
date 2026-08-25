<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance;

use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;

/** @api */
final readonly class ConformanceReport
{
    /** @var list<string> */
    public array $violations;

    /** @param list<string> $violations */
    public function __construct(array $violations)
    {
        $this->violations = ImmutableValueSanitizer::stringList(
            $violations,
            'Provider conformance violations',
        );
    }

    public function passed(): bool
    {
        return $this->violations === [];
    }

    public function assertPassed(): void
    {
        if (! $this->passed()) {
            throw new ProviderConformanceFailed($this->violations);
        }
    }
}
