<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

/** @internal */
final readonly class DatabaseLeaseDecision
{
    public function __construct(
        public string $observedAt,
        public string $deadline,
    ) {}
}
