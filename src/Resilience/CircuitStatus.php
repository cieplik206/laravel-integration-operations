<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;

/** @api */
final readonly class CircuitStatus
{
    public function __construct(
        public bool $initialized,
        public CircuitState $state,
        public int $consecutiveFailures,
        public int $activeCallPermits,
        public ?RetryAfterSeconds $retryAfter,
        public ?string $policyFingerprint,
    ) {}

    public static function idle(): self
    {
        return new self(false, CircuitState::Closed, 0, 0, null, null);
    }
}
