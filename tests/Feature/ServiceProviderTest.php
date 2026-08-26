<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\OperationControl;
use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Contracts\PendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\IntegrationOperations;
use Cieplik206\IntegrationOperations\Queries\DatabaseAuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Queries\DatabaseOperationQuery;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\RegistryFrozen;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationLeaseManager;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationProcessor;
use Cieplik206\IntegrationOperations\Runtime\DatabasePendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Runtime\EventLeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeLegacyProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativePollingExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\Tests\Support\BootIoProbe;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

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
        ->and(app(DefinitionRegistry::class)->all())->toHaveCount(4)
        ->and(app(AuthoritativeDefinitionRegistry::class)->isFrozen())->toBeTrue()
        ->and(app(AuthoritativeDefinitionRegistry::class)->all())->toHaveCount(3)
        ->and(FakeProviderExtensions::$constructionAttempts)->toBe(0)
        ->and(FakeAuthoritativeLegacyProviderExtensions::$constructionAttempts)->toBe(0)
        ->and(FakeAuthoritativeProviderExtensions::$constructionAttempts)->toBe(0)
        ->and(FakeAuthoritativePollingExtensions::$constructionAttempts)->toBe(0);
});

it('allows trusted provider registration during boot and rejects it afterwards', function (): void {
    expect(fn () => app(IntegrationOperations::class)->registerProvider(FakeSingleEffectDefinitionProvider::class))
        ->toThrow(RegistryFrozen::class)
        ->and(fn () => app(IntegrationOperations::class)->registerAuthoritativeProvider(FakeAuthoritativeDefinitionProvider::class))
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

it('resolves the lease runtime and its incident notifier through the package provider', function (): void {
    config()->set('integration-operations.hmac.active_version', 1);
    config()->set('integration-operations.hmac.keys', [1 => str_repeat('k', 32)]);
    config()->set('integration-operations.encryption.active_version', 1);
    config()->set('integration-operations.encryption.keys', [
        1 => 'base64:'.base64_encode(str_repeat('e', 32)),
    ]);

    expect(app(LeaseRecoveryIncidentNotifier::class))->toBeInstanceOf(EventLeaseRecoveryIncidentNotifier::class)
        ->and(app(AuthoritativeOperationQuery::class))->toBeInstanceOf(DatabaseAuthoritativeOperationQuery::class)
        ->and(app(OperationLeaseManager::class))->toBeInstanceOf(DatabaseOperationLeaseManager::class)
        ->and(app(OperationProcessor::class))->toBeInstanceOf(DatabaseOperationProcessor::class)
        ->and(app(OperationQuery::class))->toBeInstanceOf(DatabaseOperationQuery::class)
        ->and(app(PendingOperationDispatcher::class))->toBeInstanceOf(DatabasePendingOperationDispatcher::class);
});

it('registers safe operational commands and withholds infrastructure failure details', function (): void {
    /** @var ConsoleKernel $kernel */
    $kernel = app(ConsoleKernel::class);

    expect(array_keys($kernel->all()))
        ->toContain(
            'integration-operations:doctor',
            'integration-operations:heartbeat',
            'integration-operations:list',
            'integration-operations:reconcile',
            'integration-operations:resolve',
            'integration-operations:show',
        );

    $exitCode = $kernel->call('integration-operations:doctor');
    $output = $kernel->output();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('sensitive failure details were withheld')
        ->and($output)->not->toContain(
            'not-a-credential',
            'integration_operations_boot_must_not_connect',
            '127.0.0.1',
        );

    expect($kernel->call('integration-operations:list'))->toBe(Command::INVALID)
        ->and($kernel->output())->toContain('Provider, connection, status, or limit is invalid.')
        ->and($kernel->call('integration-operations:show', [
            'operation' => 'not-an-operation-id',
            '--provider' => 'fixture_catalog',
            '--connection' => 'tenant:1',
        ]))->toBe(Command::INVALID)
        ->and($kernel->output())->toContain('Provider, connection, or operation ID is invalid.')
        ->and($kernel->call('integration-operations:reconcile', [
            'operation' => 'not-an-operation-id',
            '--provider' => 'fixture_catalog',
            '--connection' => 'tenant:1',
        ]))->toBe(Command::INVALID)
        ->and($kernel->output())->toContain('Provider, connection, or operation ID is invalid.')
        ->and($kernel->call('integration-operations:resolve', [
            'operation' => 'not-an-operation-id',
            'decision' => 'cancel',
            '--provider' => 'fixture_catalog',
            '--connection' => 'tenant:1',
            '--reason' => 'operator_cancelled',
        ]))->toBe(Command::INVALID)
        ->and($kernel->output())->toContain('Scope, operation ID, decision, evidence, reason, or actor is invalid.')
        ->and(app()->bound(OperationControl::class))->toBeTrue();
});
