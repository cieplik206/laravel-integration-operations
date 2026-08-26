<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Enums\PollResult;
use Cieplik206\IntegrationOperations\Support\OperationResultInvariant;
use InvalidArgumentException;

/** @api */
final readonly class PollOutcome
{
    private function __construct(
        public PollResult $result,
        public string $evidenceCode,
        public ?OperationResult $operationResult,
        public ?SafeOperationFailure $safeFailure,
        public ?RetryAfterSeconds $retryAfter,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $evidenceCode) !== 1) {
            throw new InvalidArgumentException('Poll evidence code is invalid.');
        }

        if ($operationResult !== null) {
            OperationResultInvariant::assertImmutable($operationResult);
        }
    }

    public static function completed(OperationResult $result, string $evidenceCode): self
    {
        return new self(PollResult::Completed, $evidenceCode, $result, null, null);
    }

    public static function providerRejected(
        OperationResult $result,
        SafeOperationFailure $failure,
        string $evidenceCode,
    ): self {
        return new self(PollResult::ProviderRejected, $evidenceCode, $result, $failure, null);
    }

    public static function wait(string $evidenceCode, ?RetryAfterSeconds $retryAfter = null): self
    {
        return new self(PollResult::Wait, $evidenceCode, null, null, $retryAfter);
    }

    public static function sendRequired(string $evidenceCode): self
    {
        return new self(PollResult::SendRequired, $evidenceCode, null, null, null);
    }

    public static function manualReview(SafeOperationFailure $failure, string $evidenceCode): self
    {
        return new self(PollResult::ManualReview, $evidenceCode, null, $failure, null);
    }
}
