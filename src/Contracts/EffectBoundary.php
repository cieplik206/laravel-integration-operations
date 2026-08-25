<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

/**
 * Kernel-owned boundary which records a possible remote effect before I/O.
 *
 * @api
 */
interface EffectBoundary
{
    public function open(): void;

    public function wasOpened(): bool;
}
