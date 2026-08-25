<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Enums\RetryDecision;

/**
 * Provider safety decision only. Backoff, deadlines, and next-at scheduling
 * remain kernel-owned so a provider cannot bypass global retry budgets.
 *
 * @api
 */
final readonly class RetryInstruction
{
    private function __construct(public RetryDecision $decision) {}

    public static function retry(): self
    {
        return new self(RetryDecision::Retry);
    }

    public static function reconcile(): self
    {
        return new self(RetryDecision::Reconcile);
    }

    public static function fail(): self
    {
        return new self(RetryDecision::Fail);
    }

    public static function manualReview(): self
    {
        return new self(RetryDecision::ManualReview);
    }
}
