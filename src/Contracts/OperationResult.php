<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/** @api */
interface OperationResult
{
    public function resultType(): string;
}
