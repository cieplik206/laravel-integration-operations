<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Illuminate\Container\Container;
use LogicException;

/** @api */
final class DefinitionRegistry
{
    /** @var array<string, OperationDefinition> */
    private array $definitions = [];

    /** @var array<string, ContainerBindingDescriptor> */
    private array $trustedServiceBindings = [];

    /** @var array<class-string, object> */
    private array $trustedResolvedServices = [];

    private bool $frozen = false;

    public function __construct(
        private readonly OperationDefinitionValidator $validator = new OperationDefinitionValidator,
    ) {}

    /** @param class-string $provider */
    public function register(string $provider): void
    {
        if ($this->frozen) {
            throw new RegistryFrozen('Operation definition registry is frozen after application boot.');
        }

        if (! is_a($provider, OperationDefinitionProvider::class, true)) {
            throw InvalidOperationDefinition::fromViolations(['definition provider class is invalid']);
        }

        $pending = [];

        foreach ($provider::definitions() as $definition) {
            if (! $definition->provider->equals($provider::provider())) {
                throw InvalidOperationDefinition::fromViolations(['definition provider key does not match its definition']);
            }

            $this->validator->assertValid($definition);
            $key = $definition->registryKey();

            if (array_key_exists($key, $this->definitions) || array_key_exists($key, $pending)) {
                throw new DefinitionCollision("Operation definition tuple '{$key}' is already registered.");
            }

            $pending[$key] = $definition;
        }

        if ($pending === []) {
            throw InvalidOperationDefinition::fromViolations(['provider must declare at least one operation definition']);
        }

        $this->definitions = [...$this->definitions, ...$pending];
        ksort($this->definitions, SORT_STRING);
    }

    public function freeze(ContainerBindingInspector $bindings): void
    {
        if ($this->frozen) {
            throw new RegistryFrozen('Operation definition registry is already frozen.');
        }

        $violations = [];
        /** @var array<string, class-string<OperationResultCodec>> $resultCodecs */
        $resultCodecs = [];
        /** @var array<string, ContainerBindingDescriptor> $trustedServiceBindings */
        $trustedServiceBindings = [];

        foreach ($this->definitions as $definition) {
            foreach ($definition->extensionPoints() as $name => $extensionPoint) {
                $reference = $extensionPoint['reference'];

                if ($reference === null) {
                    continue;
                }

                $binding = $bindings->exactSelfBinding($reference->serviceId, $extensionPoint['contract']);

                if ($binding === null || $binding->concrete !== $reference->serviceId) {
                    $violations[] = "{$definition->registryKey()}: {$name} has no exact final self-binding";

                    continue;
                }

                if ($bindings->wasResolved($reference->serviceId)) {
                    $violations[] = "{$definition->registryKey()}: {$name} was resolved before registry freeze";

                    continue;
                }

                $concrete = $binding->concrete;
                $trustedServiceBindings[$this->serviceTuple($reference)] = $binding;

                if ($name === 'result_codec') {
                    /** @var class-string<OperationResultCodec> $codec */
                    $codec = $concrete;

                    if ($codec::schemaVersion() !== $definition->versions->resultSchema) {
                        $violations[] = "{$definition->registryKey()}: result codec schema version does not match the definition";
                    }

                    $resultType = $codec::resultType();

                    if (! EncodedResult::isValidResultType($resultType)) {
                        $violations[] = "{$definition->registryKey()}: result codec type is invalid";

                        continue;
                    }

                    $resultEnvelope = "{$resultType}|{$codec::schemaVersion()}";
                    $registeredCodec = $resultCodecs[$resultEnvelope] ?? null;

                    if ($registeredCodec !== null && $registeredCodec !== $codec) {
                        $violations[] = "{$definition->registryKey()}: result codec envelope {$resultEnvelope} collides with another codec";
                    }

                    $resultCodecs[$resultEnvelope] = $codec;
                }
            }
        }

        if ($violations !== []) {
            throw InvalidOperationDefinition::fromViolations($violations);
        }

        $this->trustedServiceBindings = $trustedServiceBindings;
        $this->frozen = true;
    }

    public function resolveTrustedService(ServiceReference $reference, Container $container): object
    {
        $frozenBinding = $this->trustedServiceBindings[$this->serviceTuple($reference)] ?? null;

        if (! $this->frozen || $frozenBinding === null) {
            throw new RegistryFrozen('Runtime service resolution requires a frozen trusted registry reference.');
        }

        $bindings = new ContainerBindingInspector($container);
        $this->assertBindingUnchanged($reference, $frozenBinding, $bindings, 'before resolution');

        $trustedResolved = $this->trustedResolvedServices[$reference->serviceId] ?? null;

        if ($bindings->wasResolved($reference->serviceId)) {
            throw InvalidOperationDefinition::fromViolations([
                "service '{$reference->serviceId}' was resolved outside the trusted registry boundary",
            ]);
        }

        if ($trustedResolved !== null) {
            return $trustedResolved;
        }

        $resolved = $container->build($reference->serviceId);
        $this->assertBindingUnchanged($reference, $frozenBinding, $bindings, 'during resolution');

        if ($bindings->wasResolved($reference->serviceId)) {
            throw InvalidOperationDefinition::fromViolations([
                "service '{$reference->serviceId}' was replaced during trusted construction",
            ]);
        }

        $resolvedClass = $resolved::class;
        $contract = $reference->contract;

        if ($resolvedClass !== $reference->serviceId || ! $resolved instanceof $contract) {
            throw InvalidOperationDefinition::fromViolations([
                "resolved service '{$resolvedClass}' does not match frozen concrete '{$reference->serviceId}'",
            ]);
        }

        $this->trustedResolvedServices[$reference->serviceId] = $resolved;

        return $resolved;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function find(ProviderKey $provider, OperationType $operationType, int $handlerVersion): ?OperationDefinition
    {
        return $this->definitions["{$provider->value}|{$operationType->value}|{$handlerVersion}"] ?? null;
    }

    /** @return list<OperationDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Operation definition registries cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Operation definition registries cannot be cloned.');
    }

    private function serviceTuple(ServiceReference $reference): string
    {
        return "{$reference->serviceId}|{$reference->contract}";
    }

    private function assertBindingUnchanged(
        ServiceReference $reference,
        ContainerBindingDescriptor $frozenBinding,
        ContainerBindingInspector $bindings,
        string $phase,
    ): void {
        $currentBinding = $bindings->exactSelfBinding($reference->serviceId, $reference->contract);

        if ($currentBinding === null || ! $frozenBinding->equals($currentBinding)) {
            throw InvalidOperationDefinition::fromViolations([
                "container binding for '{$reference->serviceId}' changed {$phase}",
            ]);
        }
    }
}
