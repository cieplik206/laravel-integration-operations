<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum OwnerMode: string
{
    case Off = 'off';
    case ShadowRead = 'shadow_read';
    case CanaryWrite = 'canary_write';
    case On = 'on';

    public function permitsRemoteWrite(): bool
    {
        return match ($this) {
            self::CanaryWrite, self::On => true,
            self::Off, self::ShadowRead => false,
        };
    }
}
