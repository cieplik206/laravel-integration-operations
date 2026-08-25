<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Support;

use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Symfony\Component\Uid\Ulid;

final class SymfonyUlidFactory implements UlidFactory
{
    public function generate(): OperationId
    {
        return new OperationId((string) new Ulid);
    }
}
