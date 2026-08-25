<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\IntegrationOperations;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\RegistryFrozen;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\Tests\Support\BootIoProbe;

it('boots against unreachable PostgreSQL and Redis without database, migration, cache, queue, credential, or HTTP I/O', function (): void {
    expect(BootIoProbe::$databaseQueries)->toBe([])
        ->and(BootIoProbe::$queuedJobs)->toBe([])
        ->and(BootIoProbe::$migrationEvents)->toBe([])
        ->and(BootIoProbe::$cacheEvents)->toBe([])
        ->and(BootIoProbe::$redisCommands)->toBe([])
        ->and(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.port'))->toBe(1)
        ->and(config('database.redis.default.port'))->toBe(1)
        ->and(config('cache.default'))->toBe('redis')
        ->and(config('queue.default'))->toBe('redis')
        ->and(app(DefinitionRegistry::class)->isFrozen())->toBeTrue()
        ->and(app(DefinitionRegistry::class)->all())->toHaveCount(1)
        ->and(FakeProviderExtensions::$constructionAttempts)->toBe(0);
});

it('allows trusted provider registration during boot and rejects it afterwards', function (): void {
    expect(fn () => app(IntegrationOperations::class)->registerProvider(FakeSingleEffectDefinitionProvider::class))
        ->toThrow(RegistryFrozen::class);
});

it('binds framework services without resolving secrets during boot', function (): void {
    expect(app(Clock::class)->now()->getTimezone()->getName())->toBe('UTC')
        ->and((string) app(UlidFactory::class)->generate())->toBeUlid()
        ->and(app(IntegrationContextConstraints::class)->maximumBytes)->toBe(2048)
        ->and(app(IntegrationContextConstraints::class)->reservedKeyFragments)->toContain('token', 'email');

    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [1 => str_repeat('k', 32)]);

    expect(app(LookupHmacKeyRing::class)->activeVersion())->toBe(1);
});
