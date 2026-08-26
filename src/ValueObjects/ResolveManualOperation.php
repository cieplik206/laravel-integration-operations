<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Enums\ManualResolutionDecision;
use Cieplik206\IntegrationOperations\Support\OperationResultInvariant;
use InvalidArgumentException;

/** @api */
final readonly class ResolveManualOperation
{
    public function __construct(
        public IntegrationScope $scope,
        public OperationId $operationId,
        public ManualResolutionDecision $decision,
        public string $reasonCode,
        public OperationActor $actor,
        public ?SafeOperationFailure $safeFailure = null,
        public ?OperationResult $result = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Manual resolution reason code is invalid.');
        }

        if ($result !== null) {
            OperationResultInvariant::assertImmutable($result);
        }

        $validEvidence = match ($decision) {
            ManualResolutionDecision::Reconcile => $safeFailure === null && $result === null,
            ManualResolutionDecision::ConfirmSucceeded => $safeFailure === null && $result !== null,
            ManualResolutionDecision::ConfirmFailed => $safeFailure !== null && $result === null,
            ManualResolutionDecision::Cancel => $safeFailure === null && $result === null,
        };

        if (! $validEvidence) {
            throw new InvalidArgumentException('Manual resolution evidence does not match its decision.');
        }
    }
}
