<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience\Storage;

use Cieplik206\IntegrationOperations\Resilience\RemoteCallScope;
use InvalidArgumentException;

/** @internal */
final readonly class ResilienceStateKey
{
    private function __construct(
        public string $scopeFingerprint,
        public string $subsystem,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $scopeFingerprint) !== 1
            || ! in_array($subsystem, ['rate', 'circuit'], true)) {
            throw new InvalidArgumentException('Resilience state key is invalid.');
        }
    }

    public static function rate(RemoteCallScope $scope): self
    {
        return new self($scope->fingerprint(), 'rate');
    }

    public static function circuit(RemoteCallScope $scope): self
    {
        return new self($scope->fingerprint(), 'circuit');
    }

    /** @internal */
    public static function circuitFingerprint(string $scopeFingerprint): self
    {
        return new self($scopeFingerprint, 'circuit');
    }

    public function cacheKey(string $prefix): string
    {
        return $prefix.'{'.$this->scopeFingerprint.'}:'.$this->subsystem;
    }
}
