<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use RuntimeException;

final class PostgresTestDatabaseGuard
{
    public static function assertFreshIsAllowed(string $database, ?string $allowFresh): void
    {
        if ($allowFresh !== '1') {
            throw new RuntimeException('The PostgreSQL destructive test gate requires explicit opt-in.');
        }

        if (preg_match('/\Aintegration_operations_(?:[a-z0-9_]+_test|[a-z0-9_]*testing)\z/D', $database) !== 1) {
            throw new RuntimeException('The PostgreSQL test database name is not allowlisted for migrate:fresh.');
        }
    }

    public static function assertConnectedDatabase(string $configured, string $connected): void
    {
        if (! hash_equals($configured, $connected)) {
            throw new RuntimeException('The connected PostgreSQL database does not match the guarded test database.');
        }
    }
}
