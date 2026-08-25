<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\IntegrationOperations;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeReadDefinitionProvider;
use Illuminate\Support\ServiceProvider;

final class ConformanceFixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        FakeProviderExtensions::$constructionAttempts = 0;
        FakeProviderExtensions::$failOnConstruction = true;
        $this->app->singleton(FakeProviderExtensions::class);
    }

    public function boot(IntegrationOperations $operations): void
    {
        $operations->registerProvider(FakeReadDefinitionProvider::class);

        $this->app->booted(function (): void {
            FakeProviderExtensions::$failOnConstruction = false;
        });
    }
}
