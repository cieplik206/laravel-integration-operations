<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;
use InvalidArgumentException;

/** @api */
final readonly class RateLimitDecision
{
    private function __construct(
        private bool $allowed,
        private ?RateLimitRejection $rejection,
        private ?RetryAfterSeconds $retryAfter,
    ) {
        if (($allowed && ($rejection !== null || $retryAfter !== null))
            || (! $allowed && $rejection === null)) {
            throw new InvalidArgumentException('Rate limit decision is invalid.');
        }
    }

    public static function allow(): self
    {
        return new self(true, null, null);
    }

    public static function deny(RateLimitRejection $rejection, ?RetryAfterSeconds $retryAfter): self
    {
        return new self(false, $rejection, $retryAfter);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function rejection(): ?RateLimitRejection
    {
        return $this->rejection;
    }

    public function retryAfter(): ?RetryAfterSeconds
    {
        return $this->retryAfter;
    }
}
