<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionCollision;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\InvalidOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\RegistryFrozen;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Registry\TerminalContract;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeReadDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\ProviderConformanceKit;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\SelfBuilding;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class MutatingRegistryAttribute {}

final class MutableFixtureDefinitionProvider implements OperationDefinitionProvider
{
    private static ProviderKey $providerKey;

    /** @var list<OperationDefinition> */
    private static array $operationDefinitions = [];

    /** @param list<OperationDefinition> $definitions */
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

final class CompatibleWrongVersionCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'fixture.wrong_result';
    }

    public static function schemaVersion(): int
    {
        return 99;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), []);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The wrong-version codec fixture is never executed.');
    }
}

final readonly class RebindingCodecDependency
{
    public string $marker;

    public function __construct(Container $container)
    {
        $this->marker = 'trusted-constructor';
        $container->singleton(
            RebindingDuringResolutionCodec::class,
            fn (): RebindingDuringResolutionCodec => new RebindingDuringResolutionCodec($this),
        );
    }
}

final class RebindingDuringResolutionCodec implements OperationResultCodec
{
    public function __construct(private readonly RebindingCodecDependency $dependency) {}

    public static function resultType(): string
    {
        return 'fixture.rebinding_result';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), [
            'dependency' => $this->dependency->marker,
        ]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The rebinding codec fixture is never executed.');
    }
}

final class CollidingResultCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return FakeProviderExtensions::resultType();
    }

    public static function schemaVersion(): int
    {
        return FakeProviderExtensions::schemaVersion();
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), []);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The colliding codec fixture is never executed.');
    }
}

final class InvalidResultTypeCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'INVALID RESULT TYPE';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        throw new LogicException('The invalid-type codec fixture is never executed.');
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The invalid-type codec fixture is never executed.');
    }
}

class NonFinalRegistryCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'fixture.non_final_result';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), []);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The non-final codec fixture is never executed.');
    }
}

abstract class AbstractRegistryCodec implements OperationResultCodec {}

#[MutatingRegistryAttribute]
final class AttributedRegistryCodec implements OperationResultCodec
{
    public static int $constructionAttempts = 0;

    public string $marker = 'safe';

    public function __construct()
    {
        self::$constructionAttempts++;
    }

    public static function resultType(): string
    {
        return 'fixture.attributed_result';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), ['marker' => $this->marker]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The attributed codec fixture is never executed.');
    }
}

final class SelfBuildingRegistryCodec implements OperationResultCodec, SelfBuilding
{
    public static int $newInstanceCalls = 0;

    public static function newInstance(): self
    {
        self::$newInstanceCalls++;

        return new self;
    }

    public static function resultType(): string
    {
        return 'fixture.self_building_result';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), []);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The self-building codec fixture is never executed.');
    }
}

/** @param array<string, mixed> $replace */
function copyOperationDefinition(OperationDefinition $definition, array $replace = []): OperationDefinition
{
    return new OperationDefinition(
        provider: $replace['provider'] ?? $definition->provider,
        operationType: $replace['operationType'] ?? $definition->operationType,
        versions: $replace['versions'] ?? $definition->versions,
        maximumRemoteWrites: $replace['maximumRemoteWrites'] ?? $definition->maximumRemoteWrites,
        boundaryMode: $replace['boundaryMode'] ?? $definition->boundaryMode,
        retryMode: $replace['retryMode'] ?? $definition->retryMode,
        safeRetryEvidence: $replace['safeRetryEvidence'] ?? $definition->safeRetryEvidence,
        ambiguousEffectAction: $replace['ambiguousEffectAction'] ?? $definition->ambiguousEffectAction,
        reconciliationResults: $replace['reconciliationResults'] ?? $definition->reconciliationResults,
        succeeded: $replace['succeeded'] ?? $definition->succeeded,
        failed: $replace['failed'] ?? $definition->failed,
        cancelled: $replace['cancelled'] ?? $definition->cancelled,
        handler: array_key_exists('handler', $replace) ? $replace['handler'] : $definition->handler,
        failureClassifier: array_key_exists('failureClassifier', $replace) ? $replace['failureClassifier'] : $definition->failureClassifier,
        retryPolicy: array_key_exists('retryPolicy', $replace) ? $replace['retryPolicy'] : $definition->retryPolicy,
        reconciliationStrategy: array_key_exists('reconciliationStrategy', $replace) ? $replace['reconciliationStrategy'] : $definition->reconciliationStrategy,
        resultCodec: array_key_exists('resultCodec', $replace) ? $replace['resultCodec'] : $definition->resultCodec,
        outcomeProjector: array_key_exists('outcomeProjector', $replace) ? $replace['outcomeProjector'] : $definition->outcomeProjector,
    );
}

/** @param class-string<OperationDefinitionProvider> $provider */
function firstRuntimeDefinition(string $provider): OperationDefinition
{
    foreach ($provider::definitions() as $definition) {
        return $definition;
    }

    throw new RuntimeException('Fixture provider has no definition.');
}

/**
 * @param  list<OperationDefinition>  $definitions
 * @return class-string<OperationDefinitionProvider>
 */
function definitionProvider(ProviderKey $provider, array $definitions): string
{
    MutableFixtureDefinitionProvider::configure($provider, $definitions);

    return MutableFixtureDefinitionProvider::class;
}

/** @return class-string<OperationDefinitionProvider> */
function conformanceProviderClass(string $provider): string
{
    if (! is_a($provider, OperationDefinitionProvider::class, true)) {
        throw new InvalidArgumentException('Invalid conformance provider fixture.');
    }

    return $provider;
}

function bindingInspectorFor(DefinitionRegistry $registry): ContainerBindingInspector
{
    $container = new Container;

    foreach ($registry->all() as $definition) {
        foreach ($definition->extensionPoints() as $extensionPoint) {
            $reference = $extensionPoint['reference'];

            if ($reference !== null && ! $container->bound($reference->serviceId)) {
                $container->singleton($reference->serviceId);
            }
        }
    }

    return new ContainerBindingInspector($container);
}

it('registers both permitted SPI 0.1 shapes and becomes immutable when frozen', function (): void {
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $registry->register(FakeSingleEffectDefinitionProvider::class);
    $registry->freeze(bindingInspectorFor($registry));

    expect($registry->all())->toHaveCount(2)
        ->and($registry->find(
            new ProviderKey('fixture_dispatch'),
            new OperationType('fixture_dispatch.message.deliver'),
            1,
        ))->not->toBeNull()
        ->and($registry->isFrozen())->toBeTrue()
        ->and(fn () => $registry->register(FakeReadDefinitionProvider::class))->toThrow(RegistryFrozen::class);
});

it('rejects provider prefix mismatch', function (): void {
    $definition = copyOperationDefinition(
        firstRuntimeDefinition(FakeReadDefinitionProvider::class),
        ['operationType' => new OperationType('fixture_dispatch.record.fetch')],
    );
    $provider = definitionProvider(new ProviderKey('fixture_catalog'), [$definition]);

    expect(fn () => (new DefinitionRegistry)->register($provider))
        ->toThrow(InvalidOperationDefinition::class, 'provider prefix');
});

it('rejects a duplicate provider operation handler tuple even when definitions differ', function (): void {
    $original = firstRuntimeDefinition(FakeSingleEffectDefinitionProvider::class);
    $different = copyOperationDefinition($original, [
        'ambiguousEffectAction' => AmbiguousEffectAction::ManualReview,
    ]);
    $provider = definitionProvider($original->provider, [$original, $different]);

    expect(fn () => (new DefinitionRegistry)->register($provider))
        ->toThrow(DefinitionCollision::class);
});

it('requires every single-effect extension point', function (string $extensionPoint): void {
    $definition = firstRuntimeDefinition(FakeSingleEffectDefinitionProvider::class);
    $invalid = copyOperationDefinition($definition, [$extensionPoint => null]);

    expect((new OperationDefinitionValidator)->violations($invalid))
        ->toContain('required extension point '.match ($extensionPoint) {
            'handler' => 'operation_handler',
            'failureClassifier' => 'failure_classifier',
            'retryPolicy' => 'retry_policy',
            'reconciliationStrategy' => 'reconciliation_strategy',
            'resultCodec' => 'result_codec',
            'outcomeProjector' => 'outcome_projector',
            default => throw new LogicException('Unknown extension point fixture.'),
        }.' is missing');
})->with([
    'handler',
    'failureClassifier',
    'retryPolicy',
    'reconciliationStrategy',
    'resultCodec',
    'outcomeProjector',
]);

it('keeps absent conclusive out of the same-operation write retry budget', function (): void {
    $definition = firstRuntimeDefinition(FakeSingleEffectDefinitionProvider::class);
    $invalid = copyOperationDefinition($definition, [
        'safeRetryEvidence' => ['request_not_started', 'absent_conclusive'],
    ]);

    expect((new OperationDefinitionValidator)->violations($invalid))
        ->toContain('single-effect SPI 0.1 permits retry only before the effect boundary is consumed');
});

it('rejects duplicate read-only safe retry evidence', function (): void {
    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $invalid = copyOperationDefinition($definition, [
        'safeRetryEvidence' => ['request_not_started', 'request_not_started'],
    ]);

    expect((new OperationDefinitionValidator)->violations($invalid))
        ->toContain('read-only operation declares unsupported safe retry evidence');
});

it('validates ambiguous-effect and complete terminal contracts at runtime', function (): void {
    $read = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $ambiguous = copyOperationDefinition($read, [
        'ambiguousEffectAction' => AmbiguousEffectAction::Reconcile,
    ]);
    $failedEffect = copyOperationDefinition($read, [
        'failed' => new TerminalContract(
            OperationStatus::Failed,
            OperationDisposition::Failed,
            [EffectState::NotApplied],
            [ResultAvailability::NotApplicable],
        ),
    ]);
    $multipleSuccessSemantics = copyOperationDefinition($read, [
        'succeeded' => new TerminalContract(
            OperationStatus::Succeeded,
            OperationDisposition::Succeeded,
            [EffectState::NotStarted],
            [ResultAvailability::Available, ResultAvailability::NotApplicable],
        ),
    ]);
    $missingSuccessResult = copyOperationDefinition($read, [
        'succeeded' => new TerminalContract(
            OperationStatus::Succeeded,
            OperationDisposition::Succeeded,
            [EffectState::NotStarted],
            [ResultAvailability::NotApplicable],
        ),
    ]);
    $validator = new OperationDefinitionValidator;

    expect($validator->violations($ambiguous))
        ->toContain('read-only operation must mark ambiguous effects as not applicable')
        ->and($validator->violations($failedEffect))
        ->toContain('read-only failure must keep the effect not_started')
        ->and($validator->violations($multipleSuccessSemantics))
        ->toContain('succeeded terminal contract result availability is invalid')
        ->and($validator->violations($missingSuccessResult))
        ->toContain('succeeded terminal contract result availability is invalid');
});

it('ships a framework-agnostic conformance kit with read and single-effect fakes', function (string $provider): void {
    $report = (new ProviderConformanceKit)->inspect(
        conformanceProviderClass($provider),
        fn (string $serviceId, string $contract): string => $serviceId,
    );

    expect($report->passed())->toBeTrue();
    $report->assertPassed();
})->with([
    'read-only fake' => FakeReadDefinitionProvider::class,
    'single-effect fake' => FakeSingleEffectDefinitionProvider::class,
]);

it('rejects a service class that does not implement its declared extension contract', function (): void {
    expect(fn (): ServiceReference => new ServiceReference(stdClass::class, OperationHandler::class))
        ->toThrow(InvalidArgumentException::class, 'does not implement');
});

it('requires final instantiable concrete extension references', function (): void {
    expect(fn (): ServiceReference => new ServiceReference(NonFinalRegistryCodec::class, OperationResultCodec::class))
        ->toThrow(InvalidArgumentException::class, 'final instantiable')
        ->and(fn (): ServiceReference => new ServiceReference(AbstractRegistryCodec::class, OperationResultCodec::class))
        ->toThrow(InvalidArgumentException::class, 'final instantiable');
});

it('rejects unresolved services and mismatched codec versions before freeze', function (): void {
    $unresolved = new DefinitionRegistry;
    $unresolved->register(FakeReadDefinitionProvider::class);

    expect(fn () => $unresolved->freeze(new ContainerBindingInspector(new Container)))
        ->toThrow(InvalidOperationDefinition::class, 'no exact final self-binding')
        ->and($unresolved->isFrozen())->toBeFalse();

    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $wrongVersion = copyOperationDefinition($definition, [
        'versions' => new OperationDefinitionVersions(1, 1, 2),
    ]);
    $registry = new DefinitionRegistry;
    $registry->register(definitionProvider($definition->provider, [$wrongVersion]));

    expect(fn () => $registry->freeze(bindingInspectorFor($registry)))
        ->toThrow(InvalidOperationDefinition::class, 'codec schema version')
        ->and($registry->isFrozen())->toBeFalse();
});

it('rejects an incompatible container binding descriptor without constructing services', function (): void {
    FakeProviderExtensions::$constructionAttempts = 0;
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class, stdClass::class);
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $inspector = new ContainerBindingInspector($container);

    expect(fn () => $registry->freeze($inspector))
        ->toThrow(InvalidOperationDefinition::class, 'no exact final self-binding')
        ->and(FakeProviderExtensions::$constructionAttempts)->toBe(0);
});

it('validates codec metadata on the exact self-bound concrete', function (): void {
    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $wrongCodec = copyOperationDefinition($definition, [
        'resultCodec' => new ServiceReference(CompatibleWrongVersionCodec::class, OperationResultCodec::class),
    ]);
    $container = new Container;
    $registry = new DefinitionRegistry;
    $registry->register(definitionProvider($definition->provider, [$wrongCodec]));

    foreach ([FakeProviderExtensions::class, CompatibleWrongVersionCodec::class] as $serviceId) {
        $container->singleton($serviceId);
    }

    expect(fn () => $registry->freeze(new ContainerBindingInspector($container)))
        ->toThrow(InvalidOperationDefinition::class, 'codec schema version')
        ->and(FakeProviderExtensions::$constructionAttempts)->toBe(0);
});

it('rejects invalid and colliding result codec envelopes', function (): void {
    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $invalidType = copyOperationDefinition($definition, [
        'resultCodec' => new ServiceReference(InvalidResultTypeCodec::class, OperationResultCodec::class),
    ]);
    $invalidRegistry = new DefinitionRegistry;
    $invalidRegistry->register(definitionProvider($definition->provider, [$invalidType]));

    expect(fn () => $invalidRegistry->freeze(bindingInspectorFor($invalidRegistry)))
        ->toThrow(InvalidOperationDefinition::class, 'result codec type is invalid');

    $colliding = copyOperationDefinition($definition, [
        'operationType' => new OperationType('fixture_catalog.record.lookup'),
        'resultCodec' => new ServiceReference(CollidingResultCodec::class, OperationResultCodec::class),
    ]);
    $collisionRegistry = new DefinitionRegistry;
    $collisionRegistry->register(definitionProvider($definition->provider, [$definition, $colliding]));

    expect(fn () => $collisionRegistry->freeze(bindingInspectorFor($collisionRegistry)))
        ->toThrow(InvalidOperationDefinition::class, 'result codec envelope fixture.operation_result|1 collides');
});

it('rejects custom typed, untyped, and scope-bound spoof closure metadata', function (): void {
    $container = new Container;
    $concrete = FakeProviderExtensions::class;
    $container->singleton(FakeProviderExtensions::class, function () use ($concrete): FakeProviderExtensions {
        return new $concrete;
    });
    $inspector = new ContainerBindingInspector($container);

    expect($inspector->effectiveConcrete(FakeProviderExtensions::class, OperationHandler::class))
        ->toBeNull();

    $container->singleton(FakeProviderExtensions::class, function () use ($concrete) {
        return new $concrete;
    });

    expect($inspector->effectiveConcrete(FakeProviderExtensions::class, OperationHandler::class))
        ->toBeNull();

    $abstract = FakeProviderExtensions::class;
    $spoof = function () use ($abstract, $concrete): FakeProviderExtensions {
        $descriptors = [$abstract => $concrete];
        $resolved = $descriptors[$abstract];

        return new $resolved;
    };
    $boundSpoof = $spoof->bindTo($container, Container::class);

    if (! $boundSpoof instanceof Closure) {
        throw new RuntimeException('Unable to construct the closure binding spoof fixture.');
    }

    $container->singleton(FakeProviderExtensions::class, $boundSpoof);

    expect($inspector->effectiveConcrete(FakeProviderExtensions::class, OperationHandler::class))
        ->toBeNull();
});

it('rejects attributed extension classes before class-level callbacks can run', function (): void {
    AttributedRegistryCodec::$constructionAttempts = 0;
    $callbackCalls = 0;
    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $attributed = copyOperationDefinition($definition, [
        'resultCodec' => new ServiceReference(AttributedRegistryCodec::class, OperationResultCodec::class),
    ]);
    $registry = new DefinitionRegistry;
    $registry->register(definitionProvider($definition->provider, [$attributed]));
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $container->singleton(AttributedRegistryCodec::class);
    $container->afterResolvingAttribute(
        MutatingRegistryAttribute::class,
        function (MutatingRegistryAttribute $_attribute, AttributedRegistryCodec $codec) use (&$callbackCalls): void {
            $callbackCalls++;
            $codec->marker = 'mutated';
        },
    );

    expect(fn () => $registry->freeze(new ContainerBindingInspector($container)))
        ->toThrow(InvalidOperationDefinition::class, 'no exact final self-binding')
        ->and(AttributedRegistryCodec::$constructionAttempts)->toBe(0)
        ->and($callbackCalls)->toBe(0);
});

it('rejects self-building extension classes before their custom factory can run', function (): void {
    SelfBuildingRegistryCodec::$newInstanceCalls = 0;
    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $selfBuilding = copyOperationDefinition($definition, [
        'resultCodec' => new ServiceReference(SelfBuildingRegistryCodec::class, OperationResultCodec::class),
    ]);
    $registry = new DefinitionRegistry;
    $registry->register(definitionProvider($definition->provider, [$selfBuilding]));
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $container->singleton(SelfBuildingRegistryCodec::class);

    expect(fn () => $registry->freeze(new ContainerBindingInspector($container)))
        ->toThrow(InvalidOperationDefinition::class, 'no exact final self-binding')
        ->and(SelfBuildingRegistryCodec::$newInstanceCalls)->toBe(0);
});

it('resolves an exact singleton only through the frozen trusted registry boundary', function (): void {
    FakeProviderExtensions::$constructionAttempts = 0;
    FakeProviderExtensions::$failOnConstruction = false;
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $definition = $registry->all()[0];
    $reference = $definition->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    $first = $registry->resolveTrustedService($reference, $container);
    $second = $registry->resolveTrustedService($reference, $container);

    expect($first)->toBeInstanceOf(FakeProviderExtensions::class)
        ->and($second)->toBe($first)
        ->and(FakeProviderExtensions::$constructionAttempts)->toBe(1);
});

it('fails closed when an exact extension service is rebound after freeze', function (): void {
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $reference = $registry->all()[0]->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    $container->singleton(FakeProviderExtensions::class, CompatibleWrongVersionCodec::class);

    expect(fn (): object => $registry->resolveTrustedService($reference, $container))
        ->toThrow(InvalidOperationDefinition::class, 'changed before resolution');
});

it('never replaces the frozen binding snapshot on a second freeze attempt', function (): void {
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $container->singleton(FakeProviderExtensions::class);

    expect(fn () => $registry->freeze(new ContainerBindingInspector($container)))
        ->toThrow(RegistryFrozen::class, 'already frozen');
});

it('prevents cloning or serializing the one-way registry lifecycle', function (bool $frozen): void {
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);

    if ($frozen) {
        $registry->freeze(bindingInspectorFor($registry));
    }

    expect(fn (): DefinitionRegistry => clone $registry)
        ->toThrow(LogicException::class, 'cannot be cloned')
        ->and(fn (): string => serialize($registry))
        ->toThrow(LogicException::class, 'cannot be serialized');
})->with([
    'before freeze' => false,
    'after freeze' => true,
]);

it('rejects a same-class custom factory rebound before trusted resolution', function (): void {
    FakeProviderExtensions::$constructionAttempts = 0;
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $reference = $registry->all()[0]->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    $container->singleton(
        FakeProviderExtensions::class,
        fn (): FakeProviderExtensions => new FakeProviderExtensions,
    );

    expect(fn (): object => $registry->resolveTrustedService($reference, $container))
        ->toThrow(InvalidOperationDefinition::class, 'changed before resolution')
        ->and(FakeProviderExtensions::$constructionAttempts)->toBe(0);
});

it('detects a same-class factory rebind during trusted construction', function (): void {
    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);
    $reboundDefinition = copyOperationDefinition($definition, [
        'resultCodec' => new ServiceReference(RebindingDuringResolutionCodec::class, OperationResultCodec::class),
    ]);
    $registry = new DefinitionRegistry;
    $registry->register(definitionProvider($definition->provider, [$reboundDefinition]));
    $container = new Container;
    $container->instance(Container::class, $container);
    $container->singleton(FakeProviderExtensions::class);
    $container->singleton(RebindingDuringResolutionCodec::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $reference = $registry->all()[0]->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    expect(fn (): object => $registry->resolveTrustedService($reference, $container))
        ->toThrow(InvalidOperationDefinition::class, 'changed during resolution');
});

it('rejects services resolved or instance-overridden outside the trusted boundary', function (string $mutation): void {
    FakeProviderExtensions::$failOnConstruction = false;
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $reference = $registry->all()[0]->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    if ($mutation === 'resolve') {
        $container->make(FakeProviderExtensions::class);
    } else {
        $container->instance(FakeProviderExtensions::class, new FakeProviderExtensions);
    }

    expect(fn (): object => $registry->resolveTrustedService($reference, $container))
        ->toThrow(InvalidOperationDefinition::class, 'resolved outside the trusted registry boundary');
})->with(['resolve', 'instance']);

it('keeps the kernel-owned identity isolated from container instance eviction', function (): void {
    FakeProviderExtensions::$failOnConstruction = false;
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $reference = $registry->all()[0]->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    $first = $registry->resolveTrustedService($reference, $container);
    $container->forgetInstance(FakeProviderExtensions::class);
    $second = $registry->resolveTrustedService($reference, $container);

    expect($second)->toBe($first);
});

it('rejects external instance replacement after trusted construction', function (string $mutation): void {
    FakeProviderExtensions::$failOnConstruction = false;
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $reference = $registry->all()[0]->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    $registry->resolveTrustedService($reference, $container);

    if ($mutation === 'resolve') {
        $container->make(FakeProviderExtensions::class);
    } else {
        $container->instance(FakeProviderExtensions::class, new FakeProviderExtensions);
    }

    expect(fn (): object => $registry->resolveTrustedService($reference, $container))
        ->toThrow(InvalidOperationDefinition::class, 'resolved outside the trusted registry boundary');
})->with(['resolve', 'instance']);

it('does not invoke service-level callbacks or extenders while constructing a trusted service', function (): void {
    FakeProviderExtensions::$failOnConstruction = false;
    $callbackCalls = 0;
    $extenderCalls = 0;
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->singleton(FakeProviderExtensions::class);
    $registry->freeze(new ContainerBindingInspector($container));
    $reference = $registry->all()[0]->resultCodec;

    if ($reference === null) {
        throw new RuntimeException('Fixture result codec reference is missing.');
    }

    $container->beforeResolving(
        FakeProviderExtensions::class,
        function () use (&$callbackCalls, $container): void {
            $callbackCalls++;
            $container->instance(FakeProviderExtensions::class, new FakeProviderExtensions);
        },
    );
    $container->extend(
        FakeProviderExtensions::class,
        function (FakeProviderExtensions $service) use (&$extenderCalls): FakeProviderExtensions {
            $extenderCalls++;

            return $service;
        },
    );

    $resolved = $registry->resolveTrustedService($reference, $container);

    expect($resolved)->toBeInstanceOf(FakeProviderExtensions::class)
        ->and($callbackCalls)->toBe(0)
        ->and($extenderCalls)->toBe(0)
        ->and($container->resolved(FakeProviderExtensions::class))->toBeFalse();
});

it('rejects non-shared exact bindings at freeze', function (): void {
    $registry = new DefinitionRegistry;
    $registry->register(FakeReadDefinitionProvider::class);
    $container = new Container;
    $container->bind(FakeProviderExtensions::class);

    expect(fn () => $registry->freeze(new ContainerBindingInspector($container)))
        ->toThrow(InvalidOperationDefinition::class, 'no exact final self-binding');
});

it('prevents trusted executable service references from becoming persistence payloads', function (): void {
    $definition = firstRuntimeDefinition(FakeReadDefinitionProvider::class);

    expect(fn (): string => serialize($definition))
        ->toThrow(LogicException::class, 'cannot be serialized or persisted');
});
