<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Illuminate\Database\Connection;
use PDO;
use RuntimeException;

final class RollbackFailingConnection extends Connection
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'rollback-failing-fixture');
    }

    public function resetAfterTest(): void
    {
        $this->transactions = 0;
    }

    protected function createTransaction(): void {}

    protected function performRollBack($toLevel): void
    {
        throw new RuntimeException('Sensitive rollback failure fixture.');
    }
}
