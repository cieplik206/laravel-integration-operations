<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests;

use Cieplik206\IntegrationOperations\IntegrationOperationsServiceProvider;
use Cieplik206\IntegrationOperations\Tests\Support\BootIoProbeServiceProvider;
use Cieplik206\IntegrationOperations\Tests\Support\ConformanceFixtureServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('integration-operations', [
            'context' => [
                'maximum_bytes' => 2048,
            ],
        ]);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'integration_operations_boot_must_not_connect',
            'username' => 'integration_operations_boot_must_not_connect',
            'password' => 'not-a-credential',
        ]);
        $app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 15,
        ]);
        $app['config']->set('cache.default', 'redis');
        $app['config']->set('queue.default', 'redis');
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BootIoProbeServiceProvider::class,
            IntegrationOperationsServiceProvider::class,
            ConformanceFixtureServiceProvider::class,
        ];
    }
}
