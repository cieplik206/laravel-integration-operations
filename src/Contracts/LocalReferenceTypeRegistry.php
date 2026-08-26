<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/** @api */
interface LocalReferenceTypeRegistry
{
    public function allows(string $type): bool;
}
