<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
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
        public ?CanonicalObject $providerObservation,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $evidenceCode) !== 1) {
            throw new InvalidArgumentException('Poll evidence code is invalid.');
        }

        if ($operationResult !== null) {
            OperationResultInvariant::assertImmutable($operationResult);
        }
    }

    public static function completed(
        OperationResult $result,
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(PollResult::Completed, $evidenceCode, $result, null, null, $providerObservation);
    }

    public static function providerRejected(
        OperationResult $result,
        SafeOperationFailure $failure,
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(PollResult::ProviderRejected, $evidenceCode, $result, $failure, null, $providerObservation);
    }

    public static function wait(
        string $evidenceCode,
        ?RetryAfterSeconds $retryAfter = null,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(PollResult::Wait, $evidenceCode, null, null, $retryAfter, $providerObservation);
    }

    public static function sendRequired(
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(PollResult::SendRequired, $evidenceCode, null, null, null, $providerObservation);
    }

    public static function manualReview(
        SafeOperationFailure $failure,
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(PollResult::ManualReview, $evidenceCode, null, $failure, null, $providerObservation);
    }
}
