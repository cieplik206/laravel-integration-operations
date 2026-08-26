<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\LocalReferenceTypeRegistry;
use InvalidArgumentException;

/** @internal */
final readonly class ConfigLocalReferenceTypeRegistry implements LocalReferenceTypeRegistry
{
    /** @var array<string, true> */
    private array $allowedTypes;

    /** @param list<string> $allowedTypes */
    public function __construct(array $allowedTypes)
    {
        $normalized = [];

        foreach ($allowedTypes as $type) {
            if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $type) !== 1) {
                throw new InvalidArgumentException('Configured local reference type is invalid.');
            }

            $normalized[$type] = true;
        }

        $this->allowedTypes = $normalized;
    }

    public function allows(string $type): bool
    {
        return isset($this->allowedTypes[$type]);
    }
}
