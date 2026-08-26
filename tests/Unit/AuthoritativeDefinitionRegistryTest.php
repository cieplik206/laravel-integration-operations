<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\InvalidOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativePollingExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeAuthoritativeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeReadDefinitionProvider;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Illuminate\Container\Container;

final class ConfigurableAuthoritativeDefinitionProvider implements AuthoritativeOperationDefinitionProvider
{
    private static ProviderKey $providerKey;

    /** @var list<AuthoritativeOperationDefinition> */
    private static array $operationDefinitions = [];

    /** @param list<AuthoritativeOperationDefinition> $definitions */
    public static function configure(ProviderKey $provider, array $definitions): void
    {
        self::$providerKey = $provider;
        self::$operationDefinitions = $definitions;
    }

    public static function provider(): ProviderKey
    {
        return self::$providerKey;
    }

    public static function definitions(): iterable
    {
        yield from self::$operationDefinitions;
    }
}

function firstAuthoritativeDefinition(): AuthoritativeOperationDefinition
{
    foreach (FakeAuthoritativeDefinitionProvider::definitions() as $definition) {
        return $definition;
    }

    throw new RuntimeException('Authoritative fixture provider has no definition.');
}

function authoritativeDefinitionWithRetryPolicy(
    AuthoritativeOperationDefinition $definition,
    ServiceReference $retryPolicy,
): AuthoritativeOperationDefinition {
    return new AuthoritativeOperationDefinition(
        provider: $definition->provider,
        operationType: $definition->operationType,
        versions: $definition->versions,
        maximumRemoteWrites: $definition->maximumRemoteWrites,
        managedMutationIdentity: $definition->managedMutationIdentity,
        boundaryMode: $definition->boundaryMode,
        initialLane: $definition->initialLane,
        successEffectPolicy: $definition->successEffectPolicy,
        writeActivation: $definition->writeActivation,
        polling: $definition->polling,
        retryMode: $definition->retryMode,
        safeRetryEvidence: $definition->safeRetryEvidence,
        ambiguousEffectAction: $definition->ambiguousEffectAction,
        reconciliationResults: $definition->reconciliationResults,
        terminalOutcomes: $definition->terminalOutcomes,
        resultEnvelope: $definition->resultEnvelope,
        transportTargets: $definition->transportTargets,
        projection: $definition->projection,
        observationProjection: $definition->observationProjection,
        compensations: $definition->compensations,
        payloadCodec: $definition->payloadCodec,
        handler: $definition->handler,
        failureClassifier: $definition->failureClassifier,
        retryPolicy: $retryPolicy,
        reconciliationStrategy: $definition->reconciliationStrategy,
        pollingStrategy: $definition->pollingStrategy,
    );
}

it('freezes only exact authoritative extension bindings and resolves fresh instances', function (): void {
    FakeAuthoritativeProviderExtensions::$constructionAttempts = 0;
    $container = new Container;
    $container->singleton(FakeAuthoritativeProviderExtensions::class);
    $container->singleton(FakeAuthoritativePollingExtensions::class);
    $registry = new AuthoritativeDefinitionRegistry;
    $registry->register(FakeAuthoritativeDefinitionProvider::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $definition = firstAuthoritativeDefinition();

    expect($registry->isFrozen())->toBeTrue()
        ->and($definition->retryPolicy->targets(AuthoritativeRetryPolicy::class))->toBeTrue()
        ->and($definition->retryPolicy->targets(RetryPolicy::class))->toBeFalse();

    $first = $registry->resolveTrustedService($definition->retryPolicy, $container);
    $second = $registry->resolveTrustedService($definition->retryPolicy, $container);

    expect($first)->toBeInstanceOf(FakeAuthoritativeProviderExtensions::class)
        ->not->toBe($second)
        ->and(FakeAuthoritativeProviderExtensions::$constructionAttempts)->toBe(2);
});

it('rejects a custom container factory instead of accepting an approximate binding', function (): void {
    $container = new Container;
    $container->singleton(
        FakeAuthoritativeProviderExtensions::class,
        fn (): FakeAuthoritativeProviderExtensions => new FakeAuthoritativeProviderExtensions,
    );
    $container->singleton(FakeAuthoritativePollingExtensions::class);
    $registry = new AuthoritativeDefinitionRegistry;
    $registry->register(FakeAuthoritativeDefinitionProvider::class);

    expect(fn () => $registry->freeze(new ContainerBindingInspector($container)))
        ->toThrow(InvalidOperationDefinition::class, 'has no exact final self-binding');
});

it('does not adapt a legacy retry policy into the authoritative registry', function (): void {
    $definition = firstAuthoritativeDefinition();
    $legacyReference = new ServiceReference(FakeProviderExtensions::class, RetryPolicy::class);
    $invalid = authoritativeDefinitionWithRetryPolicy($definition, $legacyReference);
    ConfigurableAuthoritativeDefinitionProvider::configure($definition->provider, [$invalid]);

    expect(fn () => (new AuthoritativeDefinitionRegistry)->register(ConfigurableAuthoritativeDefinitionProvider::class))
        ->toThrow(InvalidOperationDefinition::class, 'authoritative_retry_policy targets an incompatible contract')
        ->and(fn () => new ServiceReference(
            FakeProviderExtensions::class,
            AuthoritativeRetryPolicy::class,
        ))->toThrow(InvalidArgumentException::class);
});

it('keeps authoritative and legacy provider registries disjoint', function (): void {
    expect(fn () => (new AuthoritativeDefinitionRegistry)->register(FakeReadDefinitionProvider::class))
        ->toThrow(InvalidOperationDefinition::class, 'authoritative definition provider class is invalid')
        ->and(fn () => (new DefinitionRegistry)->register(FakeAuthoritativeDefinitionProvider::class))
        ->toThrow(InvalidOperationDefinition::class, 'definition provider class is invalid');
});
