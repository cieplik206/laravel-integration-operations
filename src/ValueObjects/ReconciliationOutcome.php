<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Support\OperationResultInvariant;
use InvalidArgumentException;

/**
 * Provider-neutral reconciliation conclusion with bounded, redacted evidence.
 *
 * @api
 */
final readonly class ReconciliationOutcome
{
    private function __construct(
        public ReconciliationResult $result,
        public string $evidenceCode,
        public ?OperationResult $operationResult,
        public ?SafeOperationFailure $safeFailure,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $evidenceCode) !== 1) {
            throw new InvalidArgumentException('Reconciliation evidence code is invalid.');
        }

        if ($operationResult !== null) {
            OperationResultInvariant::assertImmutable($operationResult);
        }
    }

    public static function foundExact(OperationResult $result, string $evidenceCode): self
    {
        return new self(ReconciliationResult::FoundExact, $evidenceCode, $result, null);
    }

    public static function absentConclusive(SafeOperationFailure $failure, string $evidenceCode): self
    {
        return new self(ReconciliationResult::AbsentConclusive, $evidenceCode, null, $failure);
    }

    public static function inconclusive(string $evidenceCode): self
    {
        return new self(ReconciliationResult::Inconclusive, $evidenceCode, null, null);
    }

    public static function ambiguousMatches(SafeOperationFailure $failure, string $evidenceCode): self
    {
        return new self(ReconciliationResult::AmbiguousMatches, $evidenceCode, null, $failure);
    }
}
