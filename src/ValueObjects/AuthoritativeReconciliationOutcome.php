<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Support\OperationResultInvariant;
use InvalidArgumentException;

/** @api */
final readonly class AuthoritativeReconciliationOutcome
{
    private function __construct(
        public AuthoritativeReconciliationResult $result,
        public string $evidenceCode,
        public ?OperationResult $operationResult,
        public ?SafeOperationFailure $safeFailure,
        public ?CanonicalObject $providerObservation,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $evidenceCode) !== 1) {
            throw new InvalidArgumentException('Authoritative reconciliation evidence code is invalid.');
        }

        if ($operationResult !== null) {
            OperationResultInvariant::assertImmutable($operationResult);
        }
    }

    public static function foundExact(
        OperationResult $result,
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(AuthoritativeReconciliationResult::FoundExact, $evidenceCode, $result, null, $providerObservation);
    }

    public static function absentConclusive(
        SafeOperationFailure $failure,
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(AuthoritativeReconciliationResult::AbsentConclusive, $evidenceCode, null, $failure, $providerObservation);
    }

    public static function inconclusive(
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(AuthoritativeReconciliationResult::Inconclusive, $evidenceCode, null, null, $providerObservation);
    }

    public static function ambiguousMatches(
        SafeOperationFailure $failure,
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(AuthoritativeReconciliationResult::AmbiguousMatches, $evidenceCode, null, $failure, $providerObservation);
    }

    public static function providerRejected(
        OperationResult $result,
        SafeOperationFailure $failure,
        string $evidenceCode,
        ?CanonicalObject $providerObservation = null,
    ): self {
        return new self(
            AuthoritativeReconciliationResult::ProviderRejected,
            $evidenceCode,
            $result,
            $failure,
            $providerObservation,
        );
    }
}
