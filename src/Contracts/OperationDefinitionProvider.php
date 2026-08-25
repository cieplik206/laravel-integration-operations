<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

/** @api */
interface OperationDefinitionProvider
{
    public static function provider(): ProviderKey;

    /** @return iterable<OperationDefinition> */
    public static function definitions(): iterable;
}
