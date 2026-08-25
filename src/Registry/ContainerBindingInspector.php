<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\SelfBuilding;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Inspects binding metadata without resolving or constructing the service.
 *
 * @internal
 */
final readonly class ContainerBindingInspector
{
    public function __construct(private Container $container) {}

    /**
     * @param  class-string  $serviceId
     * @param  class-string  $contract
     * @return class-string|null
     */
    public function effectiveConcrete(string $serviceId, string $contract): ?string
    {
        return $this->exactSelfBinding($serviceId, $contract)?->concrete;
    }

    /**
     * @param  class-string  $serviceId
     * @param  class-string  $contract
     */
    public function exactSelfBinding(string $serviceId, string $contract): ?ContainerBindingDescriptor
    {
        if (! $this->container->bound($serviceId) || $this->container->isAlias($serviceId)) {
            return null;
        }

        $binding = $this->container->getBindings()[$serviceId] ?? null;

        if (! is_array($binding)) {
            return null;
        }

        $concrete = $binding['concrete'] ?? null;
        $shared = $binding['shared'] ?? null;

        if (! $concrete instanceof Closure || $shared !== true) {
            return null;
        }

        $reflection = new ReflectionFunction($concrete);

        if (! $this->isFrameworkGeneratedBinding($reflection)) {
            return null;
        }

        $variables = $reflection->getStaticVariables();
        $describedAbstract = $variables['abstract'] ?? null;
        $describedConcrete = $variables['concrete'] ?? null;

        if ($describedAbstract !== $serviceId || $describedConcrete !== $serviceId) {
            return null;
        }

        $compatibleConcrete = $this->compatibleConcrete($describedConcrete, $contract);

        if ($compatibleConcrete === null) {
            return null;
        }

        return new ContainerBindingDescriptor(
            $serviceId,
            $contract,
            $compatibleConcrete,
            $concrete,
            $shared,
        );
    }

    public function wasResolved(string $serviceId): bool
    {
        return $this->container->resolved($serviceId);
    }

    private function isFrameworkGeneratedBinding(ReflectionFunction $binding): bool
    {
        if ($binding->getClosureScopeClass()?->getName() !== Container::class || $binding->getClosureThis() !== $this->container) {
            return false;
        }

        $factory = new ReflectionMethod(Container::class, 'getClosure');

        return $binding->getFileName() === $factory->getFileName()
            && $binding->getStartLine() > $factory->getStartLine()
            && $binding->getEndLine() < $factory->getEndLine();
    }

    /**
     * @return class-string|null
     */
    private function compatibleConcrete(string $concrete, string $contract): ?string
    {
        if (! class_exists($concrete) || ! is_a($concrete, $contract, true)) {
            return null;
        }

        $reflection = new ReflectionClass($concrete);

        if (! $reflection->isFinal()
            || ! $reflection->isInstantiable()
            || $reflection->getAttributes() !== []
            || $reflection->implementsInterface(SelfBuilding::class)) {
            return null;
        }

        return $concrete;
    }
}
