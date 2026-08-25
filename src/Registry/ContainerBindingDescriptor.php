<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Closure;
use LogicException;

/** @internal */
final readonly class ContainerBindingDescriptor
{
    /**
     * @param  class-string  $serviceId
     * @param  class-string  $contract
     * @param  class-string  $concrete
     */
    public function __construct(
        public string $serviceId,
        public string $contract,
        public string $concrete,
        public Closure $factory,
        public bool $shared,
    ) {}

    public function equals(self $other): bool
    {
        return $this->serviceId === $other->serviceId
            && $this->contract === $other->contract
            && $this->concrete === $other->concrete
            && $this->factory === $other->factory
            && $this->shared === $other->shared;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Container binding descriptors cannot be serialized.');
    }
}
