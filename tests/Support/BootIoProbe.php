<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

final class BootIoProbe
{
    /** @var list<string> */
    public static array $databaseQueries = [];

    /** @var list<string> */
    public static array $queuedJobs = [];

    /** @var list<string> */
    public static array $migrationEvents = [];

    /** @var list<string> */
    public static array $cacheEvents = [];

    /** @var list<string> */
    public static array $redisCommands = [];

    public static function reset(): void
    {
        self::$databaseQueries = [];
        self::$queuedJobs = [];
        self::$migrationEvents = [];
        self::$cacheEvents = [];
        self::$redisCommands = [];
    }
}
