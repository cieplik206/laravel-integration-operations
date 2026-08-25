<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\RetryMode;

/** @api */
final class OperationDefinitionValidator
{
    /** @return list<string> */
    public function violations(OperationDefinition $definition): array
    {
        $violations = [];

        if (! $definition->operationType->belongsTo($definition->provider)) {
            $violations[] = 'operation type does not have the provider prefix';
        }

        foreach ($definition->extensionPoints() as $name => $extensionPoint) {
            $reference = $extensionPoint['reference'];

            if ($name === 'reconciliation_strategy' && $definition->maximumRemoteWrites === 0) {
                if ($reference !== null) {
                    $violations[] = 'read-only operation must not declare reconciliation_strategy';
                }

                continue;
            }

            if ($reference === null) {
                $violations[] = "required extension point {$name} is missing";

                continue;
            }

            if (! $reference->targets($extensionPoint['contract'])) {
                $violations[] = "extension point {$name} targets an incompatible contract";
            }
        }

        $violations = [...$violations, ...$this->terminalContractViolations($definition)];

        if ($definition->maximumRemoteWrites === 0) {
            return [...$violations, ...$this->readOnlyViolations($definition)];
        }

        if ($definition->maximumRemoteWrites === 1) {
            return [...$violations, ...$this->singleEffectViolations($definition)];
        }

        return [...$violations, 'SPI 0.1 permits only zero or one remote write'];
    }

    public function assertValid(OperationDefinition $definition): void
    {
        $violations = $this->violations($definition);

        if ($violations !== []) {
            throw InvalidOperationDefinition::fromViolations($violations);
        }
    }

    /** @return list<string> */
    private function readOnlyViolations(OperationDefinition $definition): array
    {
        $violations = [];

        if ($definition->boundaryMode !== BoundaryMode::Forbidden) {
            $violations[] = 'read-only operation must forbid the effect boundary';
        }

        if ($definition->retryMode !== RetryMode::ReadSafe) {
            $violations[] = 'read-only operation must use read_safe retry mode';
        }

        if ($definition->safeRetryEvidence === []
            || array_diff($definition->safeRetryEvidence, ['request_not_started', 'definitive_transient_read']) !== []
            || count(array_unique($definition->safeRetryEvidence, SORT_STRING)) !== count($definition->safeRetryEvidence)) {
            $violations[] = 'read-only operation declares unsupported safe retry evidence';
        }

        if ($definition->reconciliationResults !== []) {
            $violations[] = 'read-only operation must not declare reconciliation results';
        }

        if ($definition->ambiguousEffectAction !== AmbiguousEffectAction::NotApplicable) {
            $violations[] = 'read-only operation must mark ambiguous effects as not applicable';
        }

        if ($definition->succeeded->effectStates !== [EffectState::NotStarted]) {
            $violations[] = 'read-only success must keep the effect not_started';
        }

        if ($definition->failed->effectStates !== [EffectState::NotStarted]) {
            $violations[] = 'read-only failure must keep the effect not_started';
        }

        return $violations;
    }

    /** @return list<string> */
    private function singleEffectViolations(OperationDefinition $definition): array
    {
        $violations = [];

        if ($definition->boundaryMode !== BoundaryMode::Required) {
            $violations[] = 'single-effect operation must require one effect boundary';
        }

        if ($definition->retryMode !== RetryMode::EffectAware) {
            $violations[] = 'single-effect operation must use effect_aware retry mode';
        }

        if ($definition->safeRetryEvidence !== ['request_not_started']) {
            $violations[] = 'single-effect SPI 0.1 permits retry only before the effect boundary is consumed';
        }

        if (! in_array($definition->ambiguousEffectAction, [AmbiguousEffectAction::Reconcile, AmbiguousEffectAction::ManualReview], true)) {
            $violations[] = 'single-effect operation must reconcile or request manual review for an ambiguous effect';
        }

        $requiredReconciliationResults = [
            ReconciliationResult::FoundExact,
            ReconciliationResult::AbsentConclusive,
            ReconciliationResult::Inconclusive,
            ReconciliationResult::AmbiguousMatches,
        ];

        if ($definition->reconciliationResults !== $requiredReconciliationResults) {
            $violations[] = 'single-effect operation must declare the complete reconciliation vocabulary';
        }

        if ($definition->succeeded->effectStates !== [EffectState::Applied]) {
            $violations[] = 'single-effect success must prove the effect applied';
        }

        return $violations;
    }

    /** @return list<string> */
    private function terminalContractViolations(OperationDefinition $definition): array
    {
        $violations = [];
        $expected = [
            'succeeded' => [
                $definition->succeeded,
                OperationStatus::Succeeded,
                OperationDisposition::Succeeded,
                [ResultAvailability::Available],
            ],
            'failed' => [
                $definition->failed,
                OperationStatus::Failed,
                OperationDisposition::Failed,
                [ResultAvailability::NotApplicable],
            ],
            'cancelled' => [
                $definition->cancelled,
                OperationStatus::Cancelled,
                OperationDisposition::Cancelled,
                [ResultAvailability::NotApplicable],
            ],
        ];

        foreach ($expected as $name => [$contract, $status, $disposition, $allowedResultAvailabilities]) {
            if ($contract->status !== $status || $contract->disposition !== $disposition) {
                $violations[] = "{$name} terminal contract status or disposition is invalid";
            }

            if (count($contract->resultAvailabilities) !== 1
                || ! in_array($contract->resultAvailabilities[0], $allowedResultAvailabilities, true)) {
                $violations[] = "{$name} terminal contract result availability is invalid";
            }
        }

        foreach ($definition->failed->effectStates as $effectState) {
            if (! in_array($effectState, [EffectState::NotStarted, EffectState::NotApplied], true)) {
                $violations[] = 'failed terminal contract exposes an invalid effect state';

                break;
            }
        }

        if ($definition->cancelled->effectStates !== [EffectState::NotStarted]) {
            $violations[] = 'cancelled terminal contract must keep the effect not_started';
        }

        return $violations;
    }
}
