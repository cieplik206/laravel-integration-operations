<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

final class BootIoProbeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        BootIoProbe::reset();
        Http::preventStrayRequests();
        $events = $this->app->make(Dispatcher::class);

        $events->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            BootIoProbe::$databaseQueries[] = $event->sql;
        });

        $events->listen(JobQueued::class, function (JobQueued $event): void {
            BootIoProbe::$queuedJobs[] = $event->job::class;
        });

        $events->listen(JobQueueing::class, function (JobQueueing $event): void {
            BootIoProbe::$queuedJobs[] = is_object($event->job) ? $event->job::class : get_debug_type($event->job);
        });

        $events->listen([
            MigrationStarted::class,
            MigrationEnded::class,
            MigrationsStarted::class,
            MigrationsEnded::class,
            NoPendingMigrations::class,
        ], function (object $event): void {
            BootIoProbe::$migrationEvents[] = $event::class;
        });

        $events->listen([
            RetrievingKey::class,
            CacheHit::class,
            CacheMissed::class,
            WritingKey::class,
            ForgettingKey::class,
            CacheFlushing::class,
        ], function (object $event): void {
            BootIoProbe::$cacheEvents[] = $event::class;
        });

        $events->listen(CommandExecuted::class, function (CommandExecuted $event): void {
            BootIoProbe::$redisCommands[] = $event->command;
        });
    }
}
