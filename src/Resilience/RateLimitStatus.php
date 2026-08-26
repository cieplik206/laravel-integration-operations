<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;

/** @api */
final readonly class RateLimitStatus
{
    public function __construct(
        public bool $initialized,
        public bool $suspended,
        public ?RetryAfterSeconds $retryAfter,
        public ?string $policyFingerprint,
    ) {}

    public static function idle(): self
    {
        return new self(false, false, null, null);
    }
}
