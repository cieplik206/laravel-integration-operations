<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Illuminate\Container\Container;
use LogicException;

/** @api */
final class AuthoritativeDefinitionRegistry
{
    public const int ContractVersion = 2;

    /** @var array<string, AuthoritativeOperationDefinition> */
    private array $definitions = [];

    /** @var array<string, ContainerBindingDescriptor> */
    private array $trustedServiceBindings = [];

    private bool $frozen = false;

    public function __construct(
        private readonly AuthoritativeOperationDefinitionValidator $validator = new AuthoritativeOperationDefinitionValidator,
    ) {}

    /** @param class-string $provider */
    public function register(string $provider): void
    {
        if ($this->frozen) {
            throw new RegistryFrozen('Authoritative definition registry is frozen after application boot.');
        }

        if (! is_a($provider, AuthoritativeOperationDefinitionProvider::class, true)) {
            throw InvalidOperationDefinition::fromViolations(['authoritative definition provider class is invalid']);
        }

        $pending = [];

        foreach ($provider::definitions() as $definition) {
            if (! $definition->provider->equals($provider::provider())) {
                throw InvalidOperationDefinition::fromViolations([
                    'authoritative definition provider key does not match its definition',
                ]);
            }

            $this->validator->assertValid($definition);
            $key = $definition->registryKey();

            if (isset($this->definitions[$key]) || isset($pending[$key])) {
                throw new DefinitionCollision("Authoritative definition tuple '{$key}' is already registered.");
            }

            $pending[$key] = $definition;
        }

        if ($pending === []) {
            throw InvalidOperationDefinition::fromViolations([
                'authoritative provider must declare at least one operation definition',
            ]);
        }

        $this->definitions = [...$this->definitions, ...$pending];
        ksort($this->definitions, SORT_STRING);
    }

    public function freeze(ContainerBindingInspector $bindings): void
    {
        if ($this->frozen) {
            throw new RegistryFrozen('Authoritative definition registry is already frozen.');
        }

        $violations = [];
        /** @var array<string, class-string<OperationResultCodec>> $resultCodecs */
        $resultCodecs = [];
        /** @var array<string, ContainerBindingDescriptor> $trustedServiceBindings */
        $trustedServiceBindings = [];

        $violations = [...$violations, ...$this->crossDefinitionViolations()];

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

                $trustedServiceBindings[$this->serviceTuple($reference)] = $binding;
            }

            $this->validateStaticCodecMetadata($definition, $resultCodecs, $violations);
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
            throw new RegistryFrozen('Runtime service resolution requires a frozen authoritative reference.');
        }

        $bindings = new ContainerBindingInspector($container);
        $this->assertBindingUnchanged($reference, $frozenBinding, $bindings, 'before resolution');

        if ($bindings->wasResolved($reference->serviceId)) {
            throw InvalidOperationDefinition::fromViolations([
                "service '{$reference->serviceId}' was resolved outside the authoritative registry boundary",
            ]);
        }

        $resolved = $container->build($reference->serviceId);
        $this->assertBindingUnchanged($reference, $frozenBinding, $bindings, 'during resolution');

        if ($bindings->wasResolved($reference->serviceId)) {
            throw InvalidOperationDefinition::fromViolations([
                "service '{$reference->serviceId}' was replaced during authoritative construction",
            ]);
        }

        $resolvedClass = $resolved::class;
        $contract = $reference->contract;

        if ($resolvedClass !== $reference->serviceId || ! $resolved instanceof $contract) {
            throw InvalidOperationDefinition::fromViolations([
                "resolved service '{$resolvedClass}' does not match frozen concrete '{$reference->serviceId}'",
            ]);
        }

        return $resolved;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function find(
        ProviderKey $provider,
        OperationType $operationType,
        int $handlerVersion,
    ): ?AuthoritativeOperationDefinition {
        return $this->definitions["{$provider->value}|{$operationType->value}|{$handlerVersion}"] ?? null;
    }

    public function runtimeBindingsAreAvailable(
        AuthoritativeOperationDefinition $definition,
        ContainerBindingInspector $bindings,
    ): bool {
        if (! $this->frozen || $this->find(
            $definition->provider,
            $definition->operationType,
            $definition->versions->handler,
        ) !== $definition) {
            return false;
        }

        foreach ($definition->extensionPoints() as $extensionPoint) {
            $reference = $extensionPoint['reference'];

            if ($reference === null) {
                continue;
            }

            $frozenBinding = $this->trustedServiceBindings[$this->serviceTuple($reference)] ?? null;
            $currentBinding = $bindings->exactSelfBinding($reference->serviceId, $extensionPoint['contract']);

            if ($frozenBinding === null
                || $currentBinding === null
                || ! $frozenBinding->equals($currentBinding)
                || $bindings->wasResolved($reference->serviceId)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<AuthoritativeOperationDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Authoritative definition registries cannot be serialized.');
    }

    public function __clone(): void
    {
        throw new LogicException('Authoritative definition registries cannot be cloned.');
    }

    /**
     * @param  array<string, class-string<OperationResultCodec>>  $resultCodecs
     * @param  list<string>  $violations
     */
    private function validateStaticCodecMetadata(
        AuthoritativeOperationDefinition $definition,
        array &$resultCodecs,
        array &$violations,
    ): void {
        /** @var class-string<OperationPayloadCodec> $payloadCodec */
        $payloadCodec = $definition->payloadCodec->serviceId;

        if ($payloadCodec::schemaVersion() !== $definition->versions->payloadSchema) {
            $violations[] = "{$definition->registryKey()}: payload codec schema version does not match the definition";
        }

        /** @var class-string<OperationResultCodec> $resultCodec */
        $resultCodec = $definition->resultEnvelope->resultCodec->serviceId;
        $resultType = $resultCodec::resultType();
        $resultSchema = $resultCodec::schemaVersion();

        if ($resultSchema !== $definition->resultEnvelope->schemaVersion
            || $resultSchema !== $definition->versions->resultSchema) {
            $violations[] = "{$definition->registryKey()}: result codec schema version does not match the frozen envelope";
        }

        if ($resultType !== $definition->resultEnvelope->resultType
            || ! EncodedResult::isValidResultType($resultType)) {
            $violations[] = "{$definition->registryKey()}: result codec type does not match the frozen envelope";

            return;
        }

        $resultEnvelope = "{$resultType}|{$resultSchema}";
        $registeredCodec = $resultCodecs[$resultEnvelope] ?? null;

        if ($registeredCodec !== null && $registeredCodec !== $resultCodec) {
            $violations[] = "{$definition->registryKey()}: result envelope {$resultEnvelope} collides with another codec";
        }

        $resultCodecs[$resultEnvelope] = $resultCodec;
    }

    private function serviceTuple(ServiceReference $reference): string
    {
        return "{$reference->serviceId}|{$reference->contract}";
    }

    /** @return list<string> */
    private function crossDefinitionViolations(): array
    {
        $violations = [];

        foreach ($this->definitions as $parent) {
            foreach ($parent->compensations as $compensation) {
                $children = array_values(array_filter(
                    $this->definitions,
                    static fn (AuthoritativeOperationDefinition $candidate): bool => $candidate->provider->equals($parent->provider)
                        && $candidate->operationType->equals($compensation->childType),
                ));

                if ($children === []) {
                    $violations[] = "{$parent->registryKey()}: compensation child definition is missing";

                    continue;
                }

                if (! $this->contractAllowsCompensationOutcomes($parent->terminalOutcomes, $compensation)) {
                    $violations[] = "{$parent->registryKey()}: compensation outcomes exceed the parent terminal contract";
                }

                foreach ($children as $child) {
                    if ($child->compensations !== []) {
                        $violations[] = "{$parent->registryKey()}: compensation child definition permits nested compensation";
                    }

                    if (! $this->contractAllowsCompensationOutcomes($child->terminalOutcomes, $compensation)) {
                        $violations[] = "{$parent->registryKey()}: compensation outcomes exceed the child terminal contract";
                    }
                }
            }
        }

        return $violations;
    }

    private function contractAllowsCompensationOutcomes(
        TerminalOutcomeContract $terminalOutcomes,
        CompensationContract $compensation,
    ): bool {
        foreach ($compensation->allowedTerminalOutcomes as $outcome) {
            foreach ($outcome->proofKinds as $proofKind) {
                if (! $terminalOutcomes->allows($outcome, $proofKind)) {
                    return false;
                }
            }
        }

        return true;
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
