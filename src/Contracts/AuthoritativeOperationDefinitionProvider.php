<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

/** @api */
interface AuthoritativeOperationDefinitionProvider
{
    public static function provider(): ProviderKey;

    /** @return iterable<AuthoritativeOperationDefinition> */
    public static function definitions(): iterable;
}
