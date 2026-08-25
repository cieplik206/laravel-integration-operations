<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations;

use Cieplik206\IntegrationOperations\Context\IntegrationContextCodec;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\ConfigLookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\ProviderRegistrar;
use Cieplik206\IntegrationOperations\Support\SymfonyUlidFactory;
use Cieplik206\IntegrationOperations\Support\SystemUtcClock;
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
        $this->app->singleton(ContainerBindingInspector::class);
        $this->app->singleton(DefinitionRegistry::class);
        $this->app->singleton(ProviderRegistrar::class);
        $this->app->singleton(IntegrationOperations::class);

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
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/integration-operations.php' => config_path('integration-operations.php'),
        ], 'integration-operations-config');

        $this->app->booted(function (): void {
            $bindings = $this->app->make(ContainerBindingInspector::class);

            $this->app->make(DefinitionRegistry::class)->freeze($bindings);
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
}
