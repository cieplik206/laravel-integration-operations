<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use InvalidArgumentException;
use LogicException;
use ReflectionClass;

/**
 * A trusted container reference created by provider code during application boot.
 *
 * @api
 */
final readonly class ServiceReference
{
    /**
     * @param  class-string  $serviceId
     * @param  class-string  $contract
     */
    public function __construct(
        public string $serviceId,
        public string $contract,
    ) {
        if (strlen($serviceId) > 255) {
            throw new InvalidArgumentException('Registry service ID is invalid.');
        }

        if (! interface_exists($contract)) {
            throw new InvalidArgumentException("Registry contract '{$contract}' is not an interface.");
        }

        if (! class_exists($serviceId) || ! is_a($serviceId, $contract, true)) {
            throw new InvalidArgumentException("Registry service '{$serviceId}' does not implement '{$contract}'.");
        }

        $reflection = new ReflectionClass($serviceId);

        if (! $reflection->isFinal() || ! $reflection->isInstantiable()) {
            throw new InvalidArgumentException("Registry service '{$serviceId}' must be a final instantiable concrete class.");
        }
    }

    /** @param class-string $contract */
    public function targets(string $contract): bool
    {
        return $this->contract === $contract;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Registry service references cannot be serialized or persisted.');
    }
}
