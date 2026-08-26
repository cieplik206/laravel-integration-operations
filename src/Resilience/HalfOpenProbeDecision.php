<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;
use InvalidArgumentException;

/** @api */
final readonly class HalfOpenProbeDecision
{
    private function __construct(
        private ?HalfOpenProbePermit $permit,
        private ?CircuitRejection $rejection,
        private ?RetryAfterSeconds $retryAfter,
    ) {
        if (($permit === null) === ($rejection === null)
            || ($permit !== null && $retryAfter !== null)) {
            throw new InvalidArgumentException('Half-open probe decision is invalid.');
        }
    }

    public static function allow(HalfOpenProbePermit $permit): self
    {
        return new self($permit, null, null);
    }

    public static function deny(CircuitRejection $rejection, ?RetryAfterSeconds $retryAfter): self
    {
        return new self(null, $rejection, $retryAfter);
    }

    public function allowed(): bool
    {
        return $this->permit !== null;
    }

    public function permit(): ?HalfOpenProbePermit
    {
        return $this->permit;
    }

    public function rejection(): ?CircuitRejection
    {
        return $this->rejection;
    }

    public function retryAfter(): ?RetryAfterSeconds
    {
        return $this->retryAfter;
    }
}
