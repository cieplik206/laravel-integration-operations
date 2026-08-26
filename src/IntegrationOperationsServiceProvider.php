<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations;

use Cieplik206\IntegrationOperations\Console\DoctorIntegrationOperationsCommand;
use Cieplik206\IntegrationOperations\Console\HeartbeatIntegrationOperationsCommand;
use Cieplik206\IntegrationOperations\Console\ListIntegrationOperationsCommand;
use Cieplik206\IntegrationOperations\Console\ReconcileIntegrationOperationCommand;
use Cieplik206\IntegrationOperations\Console\ResolveIntegrationOperationCommand;
use Cieplik206\IntegrationOperations\Console\ShowIntegrationOperationCommand;
use Cieplik206\IntegrationOperations\Context\IntegrationContextCodec;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Contracts\CompensationOperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\DurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Contracts\LeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Contracts\LocalReferenceTypeRegistry;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\OperationControl;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\Contracts\OperationLeaseManager;
use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Contracts\OperationTelemetry;
use Cieplik206\IntegrationOperations\Contracts\PayloadCipher;
use Cieplik206\IntegrationOperations\Contracts\PayloadEncryptionKeyRing;
use Cieplik206\IntegrationOperations\Contracts\PendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\ConfigLookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Crypto\ConfigPayloadEncryptionKeyRing;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Crypto\LaravelPayloadCipher;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Queries\DatabaseAuthoritativeOperationQuery;
use Cieplik206\IntegrationOperations\Queries\DatabaseAuthoritativeScopedOperationQueryFactory;
use Cieplik206\IntegrationOperations\Queries\DatabaseOperationQuery;
use Cieplik206\IntegrationOperations\Queries\DatabaseScopedOperationQueryFactory;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeProviderRegistrar;
use Cieplik206\IntegrationOperations\Registry\ConfigLocalReferenceTypeRegistry;
use Cieplik206\IntegrationOperations\Registry\ConfigWriterFenceResolver;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\ProviderRegistrar;
use Cieplik206\IntegrationOperations\Runtime\AuthoritativeOperationStateMachine;
use Cieplik206\IntegrationOperations\Runtime\ConfiguredIntegrationScopes;
use Cieplik206\IntegrationOperations\Runtime\DatabaseAuthoritativePollFinalizer;
use Cieplik206\IntegrationOperations\Runtime\DatabaseAuthoritativePollLeaseManager;
use Cieplik206\IntegrationOperations\Runtime\DatabaseEffectBoundaryFactory;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationControl;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationCoordinator;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationFinalizer;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationLeaseManager;
use Cieplik206\IntegrationOperations\Runtime\DatabaseOperationProcessor;
use Cieplik206\IntegrationOperations\Runtime\DatabasePendingOperationDispatcher;
use Cieplik206\IntegrationOperations\Runtime\DatabaseStoredOperationLoader;
use Cieplik206\IntegrationOperations\Runtime\DatabaseTransitionRecorder;
use Cieplik206\IntegrationOperations\Runtime\DatabaseWriterFenceAuthority;
use Cieplik206\IntegrationOperations\Runtime\EventLeaseRecoveryIncidentNotifier;
use Cieplik206\IntegrationOperations\Runtime\KernelHeartbeat;
use Cieplik206\IntegrationOperations\Runtime\LeaseTimingPolicy;
use Cieplik206\IntegrationOperations\Runtime\OperationStateMachine;
use Cieplik206\IntegrationOperations\Runtime\QueueDurableAcceptanceNotifier;
use Cieplik206\IntegrationOperations\Support\SymfonyUlidFactory;
use Cieplik206\IntegrationOperations\Support\SystemUtcClock;
use Cieplik206\IntegrationOperations\Telemetry\PsrOperationTelemetry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class IntegrationOperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/integration-operations.php', 'integration-operations');

        $this->app->singleton(Clock::class, SystemUtcClock::class);
        $this->app->singleton(UlidFactory::class, SymfonyUlidFactory::class);
        $this->app->singleton(CanonicalJsonV1::class);
        $this->app->singleton(OperationDefinitionValidator::class);
        $this->app->singleton(AuthoritativeOperationDefinitionValidator::class);
        $this->app->singleton(ContainerBindingInspector::class);
        $this->app->singleton(DefinitionRegistry::class);
        $this->app->singleton(AuthoritativeDefinitionRegistry::class);
        $this->app->singleton(ProviderRegistrar::class);
        $this->app->singleton(AuthoritativeProviderRegistrar::class);
        $this->app->singleton(IntegrationOperations::class);
        $this->app->singleton(OperationTelemetry::class, PsrOperationTelemetry::class);
        $this->app->singleton(PayloadCipher::class, LaravelPayloadCipher::class);
        $this->app->singleton(HmacSha256::class);
        $this->app->singleton(BoundPayloadEnvelopeCodec::class);
        $this->app->singleton(KernelDatabase::class);
        $this->app->singleton(OperationStateMachine::class);
        $this->app->singleton(AuthoritativeOperationStateMachine::class);
        $this->app->singleton(DatabaseAuthoritativePollLeaseManager::class);
        $this->app->singleton(DatabaseAuthoritativePollFinalizer::class);
        $this->app->singleton(DatabaseScopedOperationQueryFactory::class);
        $this->app->singleton(DatabaseAuthoritativeScopedOperationQueryFactory::class);
        $this->app->singleton(ConfiguredIntegrationScopes::class);
        $this->app->singleton(KernelHeartbeat::class);
        $this->app->singleton(DatabaseTransitionRecorder::class);
        $this->app->singleton(DatabaseWriterFenceAuthority::class);
        $this->app->singleton(DatabaseEffectBoundaryFactory::class);
        $this->app->singleton(DatabaseStoredOperationLoader::class);
        $this->app->singleton(DatabaseOperationFinalizer::class);
        $this->app->singleton(LeaseTimingPolicy::class);
        $this->app->singleton(WriterFenceResolver::class, ConfigWriterFenceResolver::class);
        $this->app->singleton(DurableAcceptanceNotifier::class, QueueDurableAcceptanceNotifier::class);
        $this->app->singleton(LeaseRecoveryIncidentNotifier::class, EventLeaseRecoveryIncidentNotifier::class);
        $this->app->singleton(OperationCoordinator::class, DatabaseOperationCoordinator::class);
        $this->app->singleton(CompensationOperationCoordinator::class, DatabaseOperationCoordinator::class);
        $this->app->singleton(OperationControl::class, DatabaseOperationControl::class);
        $this->app->singleton(OperationLeaseManager::class, DatabaseOperationLeaseManager::class);
        $this->app->singleton(OperationProcessor::class, DatabaseOperationProcessor::class);
        $this->app->singleton(OperationQuery::class, DatabaseOperationQuery::class);
        $this->app->singleton(AuthoritativeOperationQuery::class, DatabaseAuthoritativeOperationQuery::class);
        $this->app->singleton(PendingOperationDispatcher::class, DatabasePendingOperationDispatcher::class);

        $this->app->singleton(
            IntegrationContextConstraints::class,
            fn (Application $app): IntegrationContextConstraints => $this->contextConstraints($app),
        );
        $this->app->singleton(
            IntegrationContextCodec::class,
            fn (Application $app): IntegrationContextCodec => new IntegrationContextCodec(
                $app->make(IntegrationContextConstraints::class),
                $app->make(CanonicalJsonV1::class),
            ),
        );
        $this->app->singleton(
            LookupHmacKeyRing::class,
            fn (Application $app): LookupHmacKeyRing => $this->hmacKeyRing($app),
        );
        $this->app->singleton(
            PayloadEncryptionKeyRing::class,
            fn (Application $app): PayloadEncryptionKeyRing => $this->payloadEncryptionKeyRing($app),
        );
        $this->app->singleton(
            LocalReferenceTypeRegistry::class,
            fn (Application $app): LocalReferenceTypeRegistry => $this->localReferenceTypeRegistry($app),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/integration-operations.php' => config_path('integration-operations.php'),
        ], 'integration-operations-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorIntegrationOperationsCommand::class,
                HeartbeatIntegrationOperationsCommand::class,
                ListIntegrationOperationsCommand::class,
                ReconcileIntegrationOperationCommand::class,
                ResolveIntegrationOperationCommand::class,
                ShowIntegrationOperationCommand::class,
            ]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $enabled = $this->app->make('config')->get('integration-operations.scheduler.enabled', true);

                if (! is_bool($enabled)) {
                    throw new InvalidArgumentException('Integration operation scheduler enabled flag is invalid.');
                }

                if (! $enabled) {
                    return;
                }

                $schedule->command('integration-operations:heartbeat')
                    ->everyMinute()
                    ->withoutOverlapping(10)
                    ->onOneServer();
            });
        }

        $this->app->booted(function (): void {
            $bindings = $this->app->make(ContainerBindingInspector::class);

            $this->app->make(DefinitionRegistry::class)->freeze($bindings);
            $this->app->make(AuthoritativeDefinitionRegistry::class)->freeze($bindings);
        });
    }

    private function contextConstraints(Application $app): IntegrationContextConstraints
    {
        $config = $app->make('config');
        $reservedKeys = $config->get('integration-operations.context.reserved_key_fragments');

        if ($reservedKeys === null) {
            $reservedKeys = (new IntegrationContextConstraints)->reservedKeyFragments;
        }

        if (! is_array($reservedKeys) || $reservedKeys === [] || array_filter($reservedKeys, fn (mixed $value): bool => ! is_string($value)) !== []) {
            throw new InvalidArgumentException('Integration context reserved key configuration is invalid.');
        }

        return new IntegrationContextConstraints(
            maximumAttributes: (int) $config->get('integration-operations.context.maximum_attributes', 24),
            maximumBytes: (int) $config->get('integration-operations.context.maximum_bytes', 4096),
            maximumKeyBytes: (int) $config->get('integration-operations.context.maximum_key_bytes', 64),
            maximumStringBytes: (int) $config->get('integration-operations.context.maximum_string_bytes', 512),
            maximumCorrelationIdBytes: (int) $config->get('integration-operations.context.maximum_correlation_id_bytes', 255),
            reservedKeyFragments: array_values($reservedKeys),
        );
    }

    private function hmacKeyRing(Application $app): LookupHmacKeyRing
    {
        $config = $app->make('config');
        $keys = $config->get('integration-operations.hmac.keys', []);

        return new ConfigLookupHmacKeyRing(
            activeVersion: (int) $config->get('integration-operations.hmac.active_version', 1),
            configuredKeys: is_array($keys) ? $keys : [],
        );
    }

    private function payloadEncryptionKeyRing(Application $app): PayloadEncryptionKeyRing
    {
        $config = $app->make('config');
        $keys = $config->get('integration-operations.encryption.keys', []);

        return new ConfigPayloadEncryptionKeyRing(
            activeVersion: (int) $config->get('integration-operations.encryption.active_version', 1),
            configuredCipher: (string) $config->get('integration-operations.encryption.cipher', 'AES-256-GCM'),
            configuredKeys: is_array($keys) ? $keys : [],
        );
    }

    private function localReferenceTypeRegistry(Application $app): LocalReferenceTypeRegistry
    {
        $configured = $app->make('config')->get('integration-operations.local_references.allowed_types', []);

        if (! is_array($configured)
            || ! array_is_list($configured)
            || array_filter($configured, fn (mixed $type): bool => ! is_string($type)) !== []) {
            throw new InvalidArgumentException('Integration operation local reference allowlist is invalid.');
        }

        /** @var list<string> $configured */
        return new ConfigLocalReferenceTypeRegistry($configured);
    }
}
