<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance\Fakes;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;

final readonly class FakeAuthoritativeOperationResult implements OperationResult
{
    public function __construct(public string $value) {}

    public function resultType(): string
    {
        return FakeAuthoritativeProviderExtensions::resultType();
    }
}
