<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\IntegrationOperations;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeLegacyDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeLegacyProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativePollingExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeReadDefinitionProvider;
use Illuminate\Support\ServiceProvider;

final class ConformanceFixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        FakeProviderExtensions::$constructionAttempts = 0;
        FakeProviderExtensions::$failOnConstruction = true;
        FakeAuthoritativeLegacyProviderExtensions::$constructionAttempts = 0;
        FakeAuthoritativeLegacyProviderExtensions::$failOnConstruction = true;
        FakeAuthoritativeLegacyProviderExtensions::$openEffectBoundary = false;
        FakeAuthoritativeProviderExtensions::$constructionAttempts = 0;
        FakeAuthoritativeProviderExtensions::$failOnConstruction = true;
        FakeAuthoritativePollingExtensions::$constructionAttempts = 0;
        FakeAuthoritativePollingExtensions::$failOnConstruction = true;
        FakeAuthoritativePollingExtensions::$sendRequiredOnce = false;
        $this->app->singleton(FakeProviderExtensions::class);
        $this->app->singleton(FakeAuthoritativeLegacyProviderExtensions::class);
        $this->app->singleton(FakeAuthoritativeProviderExtensions::class);
        $this->app->singleton(FakeAuthoritativePollingExtensions::class);
    }

    public function boot(IntegrationOperations $operations): void
    {
        $operations->registerProvider(FakeReadDefinitionProvider::class);
        $operations->registerProvider(FakeAuthoritativeLegacyDefinitionProvider::class);
        $operations->registerAuthoritativeProvider(FakeAuthoritativeDefinitionProvider::class);

        $this->app->booted(function (): void {
            FakeProviderExtensions::$failOnConstruction = false;
            FakeAuthoritativeLegacyProviderExtensions::$failOnConstruction = false;
            FakeAuthoritativeProviderExtensions::$failOnConstruction = false;
            FakeAuthoritativePollingExtensions::$failOnConstruction = false;
        });
    }
}
