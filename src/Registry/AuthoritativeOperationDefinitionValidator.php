<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\PollingStrategy;
use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\InitialOperationLane;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\RetryMode;
use Cieplik206\IntegrationOperations\Enums\SuccessEffectPolicy;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Enums\WriteActivation;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Throwable;

/**
 * Pure definition validation performed before registry freeze.
 *
 * Child-definition existence/depth and child terminal-contract compatibility,
 * writer-target registry membership, and actual encrypted-envelope sizes remain
 * freeze/finalization invariants because they require registry-wide state or data.
 *
 * @api
 */
final class AuthoritativeOperationDefinitionValidator
{
    /** @return list<string> */
    public function violations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [
            ...$this->identityAndVersionViolations($definition),
            ...$this->extensionPointViolations($definition),
            ...$this->staticCodecMetadataViolations($definition),
            ...$this->boundedDescriptorViolations($definition),
            ...$this->canonicalOrderingViolations($definition),
            ...$this->reconciliationViolations($definition),
            ...$this->pollingViolations($definition),
            ...$this->operationProfileViolations($definition),
            ...$this->terminalOutcomeViolations($definition),
            ...$this->compensationViolations($definition),
        ];

        $violations = array_values(array_unique($violations));
        sort($violations, SORT_STRING);

        return $violations;
    }

    public function assertValid(AuthoritativeOperationDefinition $definition): void
    {
        $violations = $this->violations($definition);

        if ($violations !== []) {
            throw InvalidOperationDefinition::fromViolations($violations);
        }
    }

    /** @return list<string> */
    private function identityAndVersionViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];

        if (! $definition->operationType->belongsTo($definition->provider)) {
            $violations[] = 'operation type does not have the provider prefix';
        }

        if ($definition->versions->payloadSchema < 1
            || $definition->versions->payloadSchema > 65_535
            || $definition->versions->handler < 1
            || $definition->versions->handler > 65_535
            || $definition->versions->resultSchema < 1
            || $definition->versions->resultSchema > 65_535) {
            $violations[] = 'authoritative definition versions exceed durable storage bounds';
        }

        if ($definition->versions->resultSchema !== $definition->resultEnvelope->schemaVersion) {
            $violations[] = 'result envelope schema does not match the definition result schema';
        }

        return $violations;
    }

    /** @return list<string> */
    private function extensionPointViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];
        $required = [
            'payload_codec' => [$definition->payloadCodec, OperationPayloadCodec::class],
            'operation_handler' => [$definition->handler, OperationHandler::class],
            'authoritative_failure_classifier' => [$definition->failureClassifier, AuthoritativeFailureClassifier::class],
            'authoritative_retry_policy' => [$definition->retryPolicy, AuthoritativeRetryPolicy::class],
            'result_codec' => [$definition->resultEnvelope->resultCodec, OperationResultCodec::class],
            'outcome_projection_planner' => [$definition->projection->planner, OutcomeProjectionPlanner::class],
        ];

        foreach ($required as $name => [$reference, $contract]) {
            if (! $reference->targets($contract)) {
                $violations[] = "extension point {$name} targets an incompatible contract";
            }
        }

        if ($definition->reconciliationStrategy !== null
            && ! $definition->reconciliationStrategy->targets(AuthoritativeReconciliationStrategy::class)) {
            $violations[] = 'authoritative reconciliation strategy targets an incompatible contract';
        }

        if ($definition->pollingStrategy !== null
            && ! $definition->pollingStrategy->targets(PollingStrategy::class)) {
            $violations[] = 'polling strategy targets an incompatible contract';
        }

        if ($definition->observationProjection !== null
            && ! $definition->observationProjection->targetsPlannerContract(ObservationProjectionPlanner::class)) {
            $violations[] = 'observation projection planner targets an incompatible contract';
        }

        return $violations;
    }

    /** @return list<string> */
    private function staticCodecMetadataViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];

        if ($definition->payloadCodec->targets(OperationPayloadCodec::class)) {
            try {
                /** @var class-string<OperationPayloadCodec> $payloadCodec */
                $payloadCodec = $definition->payloadCodec->serviceId;

                $codecSchemaVersion = $payloadCodec::schemaVersion();

                if ($codecSchemaVersion < 1 || $codecSchemaVersion > 65_535) {
                    $violations[] = 'payload codec schema version is outside durable bounds';
                }

                if ($codecSchemaVersion !== $definition->versions->payloadSchema) {
                    $violations[] = 'payload codec schema version does not match the definition';
                }
            } catch (Throwable) {
                $violations[] = 'payload codec static metadata is unavailable';
            }
        }

        if ($definition->resultEnvelope->resultCodec->targets(OperationResultCodec::class)) {
            try {
                /** @var class-string<OperationResultCodec> $resultCodec */
                $resultCodec = $definition->resultEnvelope->resultCodec->serviceId;
                $codecSchemaVersion = $resultCodec::schemaVersion();
                $codecResultType = $resultCodec::resultType();

                if ($codecSchemaVersion < 1 || $codecSchemaVersion > 65_535) {
                    $violations[] = 'result codec schema version is outside durable bounds';
                }

                if ($codecSchemaVersion !== $definition->versions->resultSchema
                    || $codecSchemaVersion !== $definition->resultEnvelope->schemaVersion) {
                    $violations[] = 'result codec schema version does not match the result envelope';
                }

                if (! EncodedResult::isValidResultType($codecResultType)) {
                    $violations[] = 'result codec type is outside canonical bounds';
                }

                if ($codecResultType !== $definition->resultEnvelope->resultType) {
                    $violations[] = 'result codec type does not match the result envelope';
                }
            } catch (Throwable) {
                $violations[] = 'result codec static metadata is unavailable';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function boundedDescriptorViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];

        try {
            new ResultEnvelopeDescriptor(
                $definition->resultEnvelope->resultCodec,
                $definition->resultEnvelope->resultType,
                $definition->resultEnvelope->schemaVersion,
                $definition->resultEnvelope->maximumPlaintextBytes,
                $definition->resultEnvelope->maximumCiphertextBytes,
            );
        } catch (Throwable) {
            $violations[] = 'result envelope descriptor is outside canonical bounds';
        }

        if (! $this->projectionContractIsCanonical($definition->projection)) {
            $violations[] = 'outcome projection contract is outside canonical bounds';
        }

        if ($definition->observationProjection !== null
            && ! $this->projectionContractIsCanonical($definition->observationProjection)) {
            $violations[] = 'observation projection contract is outside canonical bounds';
        }

        if ($definition->polling !== null && ! $this->pollingContractIsCanonical($definition->polling)) {
            $violations[] = 'polling contract is outside canonical bounds';
        }

        if (count($definition->transportTargets) > 8) {
            $violations[] = 'transport target definitions exceed their canonical bound';
        }

        $transportTargetIds = [];

        foreach ($definition->transportTargets as $target) {
            if (isset($transportTargetIds[$target->targetId])) {
                $violations[] = 'transport target IDs must be unique';
            }

            $transportTargetIds[$target->targetId] = true;

            try {
                $canonical = new TransportTargetDefinition(
                    $target->targetId,
                    $target->transport,
                    $target->method,
                    $target->targetTemplate,
                );

                if ($canonical->placeholderNames !== $target->placeholderNames) {
                    $violations[] = "transport target {$target->targetId} placeholders are not canonical";
                }
            } catch (Throwable) {
                $violations[] = "transport target {$target->targetId} is outside canonical bounds";
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function canonicalOrderingViolations(AuthoritativeOperationDefinition $definition): array
    {
        $orderedLists = [
            $definition->safeRetryEvidence,
            $this->reconciliationResultValues($definition),
            array_keys($definition->writeActivation->writeActivationSlots),
            array_map(
                static fn (TransportTargetDefinition $target): string => $target->targetId,
                $definition->transportTargets,
            ),
            $definition->projection->targetIds,
            $definition->observationProjection->targetIds ?? [],
            array_map(
                static fn (TerminalOutcomePair $pair): string => $pair->key(),
                $definition->terminalOutcomes->pairs,
            ),
            array_map(
                static fn (CompensationContract $compensation): string => "{$compensation->slot}|{$compensation->childType->value}",
                $definition->compensations,
            ),
        ];

        if ($definition->managedMutationIdentity !== null) {
            $orderedLists[] = $definition->managedMutationIdentity->semanticSlots;
        }

        foreach ($definition->terminalOutcomes->pairs as $pair) {
            $orderedLists[] = $this->proofKindValuesWithoutSorting($pair->proofKinds);
        }

        foreach ($definition->compensations as $compensation) {
            $orderedLists[] = array_map(
                static fn (TerminalOutcomePair $outcome): string => $outcome->key(),
                $compensation->allowedTerminalOutcomes,
            );

            foreach ($compensation->allowedTerminalOutcomes as $outcome) {
                $orderedLists[] = $this->proofKindValuesWithoutSorting($outcome->proofKinds);
            }
        }

        foreach ($orderedLists as $values) {
            if (! $this->stringListIsCanonicallyOrdered($values)) {
                return ['authoritative definition collections must use deterministic canonical ordering'];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function reconciliationViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];
        $requiresReconciliation = $definition->maximumRemoteWrites === 1
            && $definition->ambiguousEffectAction === AmbiguousEffectAction::Reconcile;

        if ($requiresReconciliation) {
            if ($definition->reconciliationStrategy === null) {
                $violations[] = 'reconciliation-enabled operation must declare an authoritative strategy';
            }

            if ($this->reconciliationResultValues($definition) !== $this->canonicalReconciliationResultValues()) {
                $violations[] = 'reconciliation-enabled operation must declare the canonical authoritative result vocabulary';
            }

            return $violations;
        }

        if ($definition->reconciliationStrategy !== null || $definition->reconciliationResults !== []) {
            $violations[] = 'operation without reconciliation must not declare its strategy or result vocabulary';
        }

        return $violations;
    }

    /** @return list<string> */
    private function pollingViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];
        $hasPollingContract = $definition->polling !== null;

        if ($hasPollingContract !== ($definition->pollingStrategy !== null)
            || $hasPollingContract !== ($definition->observationProjection !== null)) {
            $violations[] = 'polling contract, strategy, and observation projection must be declared together';
        }

        if ($definition->initialLane === InitialOperationLane::Poll && ! $hasPollingContract) {
            $violations[] = 'poll-first operation must declare the durable polling contract';
        }

        return $violations;
    }

    /** @return list<string> */
    private function operationProfileViolations(AuthoritativeOperationDefinition $definition): array
    {
        return match ($definition->maximumRemoteWrites) {
            0 => $this->readOnlyViolations($definition),
            1 => $this->singleEffectViolations($definition),
            default => ['authoritative definitions permit only zero or one remote write'],
        };
    }

    /** @return list<string> */
    private function readOnlyViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];

        if ($definition->managedMutationIdentity !== null) {
            $violations[] = 'read-only operation must not declare a managed mutation identity contract';
        }

        if ($definition->boundaryMode !== BoundaryMode::Forbidden
            || $definition->initialLane !== InitialOperationLane::Execute
            || $definition->successEffectPolicy !== SuccessEffectPolicy::ReadOnly) {
            $violations[] = 'read-only operation must use the forbidden-boundary execute lane and read-only effect policy';
        }

        if ($definition->polling !== null
            || $definition->pollingStrategy !== null
            || $definition->observationProjection !== null) {
            $violations[] = 'read-only operation must not declare the durable poll lane';
        }

        if ($definition->retryMode !== RetryMode::ReadSafe
            || $definition->safeRetryEvidence !== ['definitive_transient_read', 'request_not_started']) {
            $violations[] = 'read-only operation must declare the canonical read-safe retry vocabulary';
        }

        if ($definition->ambiguousEffectAction !== AmbiguousEffectAction::NotApplicable) {
            $violations[] = 'read-only operation must mark ambiguous effects as not applicable';
        }

        if (! $this->writeActivationsAre($definition, [WriteActivation::Disabled])) {
            $violations[] = 'read-only write activation slots must all be disabled';
        }

        return $violations;
    }

    /** @return list<string> */
    private function singleEffectViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];

        if ($definition->managedMutationIdentity === null) {
            $violations[] = 'single-effect operation must declare a managed mutation identity contract';
        }

        if ($definition->retryMode !== RetryMode::EffectAware
            || $definition->safeRetryEvidence !== ['request_not_started']) {
            $violations[] = 'single-effect operation may retry only before the effect boundary';
        }

        if (! in_array(
            $definition->ambiguousEffectAction,
            [AmbiguousEffectAction::Reconcile, AmbiguousEffectAction::ManualReview],
            true,
        )) {
            $violations[] = 'single-effect operation must reconcile or request manual review for an ambiguous effect';
        }

        if ($definition->initialLane === InitialOperationLane::Execute) {
            return [...$violations, ...$this->immediateWriteViolations($definition)];
        }

        return [...$violations, ...$this->pollActivatedWriteViolations($definition)];
    }

    /** @return list<string> */
    private function immediateWriteViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];

        if ($definition->boundaryMode !== BoundaryMode::Required
            || $definition->successEffectPolicy !== SuccessEffectPolicy::MustBeAppliedByOperation) {
            $violations[] = 'immediate single-effect operation must require the boundary and an applied effect';
        }

        if (! $this->writeActivationsAre($definition, [WriteActivation::ImmediateExecute])) {
            $violations[] = 'immediate single-effect activation slots must all execute immediately';
        }

        return $violations;
    }

    /** @return list<string> */
    private function pollActivatedWriteViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];

        if ($definition->polling === null
            || $definition->boundaryMode !== BoundaryMode::Optional
            || $definition->successEffectPolicy !== SuccessEffectPolicy::MayBeObservedExternally) {
            $violations[] = 'poll-activated operation must declare optional-boundary externally-observed semantics';
        }

        if (! $this->writeActivationsAre(
            $definition,
            [WriteActivation::Disabled, WriteActivation::PollSendRequired],
        )) {
            $violations[] = 'poll-activated operation contains an unreachable activation mode';
        }

        if (! in_array(
            WriteActivation::PollSendRequired,
            $definition->writeActivation->writeActivationSlots,
            true,
        )) {
            $violations[] = 'single-effect poll operation must expose at least one guarded send-required slot';
        }

        return $violations;
    }

    /**
     * Contract version 2 exposes no frozen provider-evidence resolver binding.
     * Sealed proof is therefore unreachable and must fail closed until the
     * definition carries an exact package-owned binding and version.
     *
     * @return list<string>
     */
    private function terminalOutcomeViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];
        $expected = $this->expectedTerminalOutcomes($definition);
        $actual = [];

        foreach ($definition->terminalOutcomes->pairs as $pair) {
            $key = $pair->key();
            $actual[$key] = $this->proofKindValues($pair->proofKinds);

            if (in_array(TerminalProofKind::SealedProviderEvidence, $pair->proofKinds, true)) {
                $violations[] = 'sealed provider evidence proof requires a frozen resolver binding';
            }

            if (! isset($expected[$key])) {
                $violations[] = "terminal outcome {$key} is unreachable for the operation profile";

                continue;
            }

            if ($actual[$key] !== $expected[$key]) {
                $violations[] = "terminal outcome {$key} declares a non-canonical proof set";
            }
        }

        foreach (array_keys($expected) as $key) {
            if (! isset($actual[$key])) {
                $violations[] = "terminal outcome {$key} is missing";
            }
        }

        return $violations;
    }

    /** @return array<string, list<string>> */
    private function expectedTerminalOutcomes(AuthoritativeOperationDefinition $definition): array
    {
        $expected = [
            $this->terminalKey(
                OperationStatus::Failed,
                EffectState::NotStarted,
                ResultAvailability::NotApplicable,
            ) => $this->proofKindValues([
                TerminalProofKind::Operator,
                TerminalProofKind::PreEffect,
            ]),
            $this->terminalKey(
                OperationStatus::Cancelled,
                EffectState::NotStarted,
                ResultAvailability::NotApplicable,
            ) => $this->proofKindValues([TerminalProofKind::Operator]),
        ];

        if ($definition->maximumRemoteWrites === 0) {
            $expected[$this->terminalKey(
                OperationStatus::Succeeded,
                EffectState::NotStarted,
                ResultAvailability::Available,
            )] = $this->proofKindValues([TerminalProofKind::Execute]);

            ksort($expected, SORT_STRING);

            return $expected;
        }

        if ($definition->maximumRemoteWrites !== 1) {
            return [];
        }

        $usesReconciliation = $definition->ambiguousEffectAction === AmbiguousEffectAction::Reconcile;
        $usesPolling = $definition->polling !== null;

        if ($definition->initialLane === InitialOperationLane::Poll) {
            $expected[$this->terminalKey(
                OperationStatus::Succeeded,
                EffectState::NotStarted,
                ResultAvailability::Available,
            )] = $this->proofKindValues([TerminalProofKind::Poll]);

            $preWriteFailureProofKinds = [TerminalProofKind::Poll];

            if ($usesReconciliation) {
                $preWriteFailureProofKinds[] = TerminalProofKind::Reconcile;
            }

            $expected[$this->terminalKey(
                OperationStatus::Failed,
                EffectState::NotStarted,
                ResultAvailability::Available,
            )] = $this->proofKindValues($preWriteFailureProofKinds);
        }

        $successProofKinds = [TerminalProofKind::Execute];

        if ($usesPolling) {
            $successProofKinds[] = TerminalProofKind::Poll;
        }

        if ($usesReconciliation) {
            $successProofKinds[] = TerminalProofKind::Reconcile;
            $expected[$this->terminalKey(
                OperationStatus::Failed,
                EffectState::NotApplied,
                ResultAvailability::NotApplicable,
            )] = $this->proofKindValues([TerminalProofKind::Reconcile]);
        }

        $expected[$this->terminalKey(
            OperationStatus::Succeeded,
            EffectState::Applied,
            ResultAvailability::Available,
        )] = $this->proofKindValues($successProofKinds);

        $providerRejectionProofKinds = [];

        if ($usesPolling) {
            $providerRejectionProofKinds[] = TerminalProofKind::Poll;
        }

        if ($usesReconciliation) {
            $providerRejectionProofKinds[] = TerminalProofKind::Reconcile;
        }

        if ($providerRejectionProofKinds !== []) {
            $expected[$this->terminalKey(
                OperationStatus::Failed,
                EffectState::Applied,
                ResultAvailability::Available,
            )] = $this->proofKindValues($providerRejectionProofKinds);
        }

        ksort($expected, SORT_STRING);

        return $expected;
    }

    /** @return list<string> */
    private function compensationViolations(AuthoritativeOperationDefinition $definition): array
    {
        $violations = [];
        $parentOutcomes = [];
        $requiredSuccessKey = $this->terminalKey(
            OperationStatus::Succeeded,
            EffectState::Applied,
            ResultAvailability::Available,
        );

        foreach ($definition->terminalOutcomes->pairs as $pair) {
            $parentOutcomes[$pair->key()] = $this->proofKindValues($pair->proofKinds);
        }

        foreach ($definition->compensations as $compensation) {
            $hasRequiredSuccess = false;

            foreach ($compensation->allowedTerminalOutcomes as $outcome) {
                $hasRequiredSuccess = $hasRequiredSuccess || $outcome->key() === $requiredSuccessKey;
                $parentProofKinds = $parentOutcomes[$outcome->key()] ?? null;

                if ($parentProofKinds === null
                    || array_diff($this->proofKindValues($outcome->proofKinds), $parentProofKinds) !== []) {
                    $violations[] = "compensation slot {$compensation->slot} permits an outcome outside the parent terminal contract";
                }

                if ($outcome->status === OperationStatus::Failed
                    && ($outcome->effectState !== EffectState::Applied
                        || $outcome->resultAvailability !== ResultAvailability::Available
                        || $outcome->proofKinds === [])) {
                    $violations[] = "compensation slot {$compensation->slot} has an unsafe failed-outcome eligibility";
                }
            }

            if (! $hasRequiredSuccess) {
                $violations[] = "compensation slot {$compensation->slot} must include succeeded applied available eligibility";
            }
        }

        return $violations;
    }

    /** @param list<WriteActivation> $allowed */
    private function writeActivationsAre(
        AuthoritativeOperationDefinition $definition,
        array $allowed,
    ): bool {
        foreach ($definition->writeActivation->writeActivationSlots as $activation) {
            if (! in_array($activation, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function projectionContractIsCanonical(ProjectionContract $contract): bool
    {
        try {
            $canonical = new ProjectionContract(
                $contract->planner,
                $contract->schemaVersion,
                $contract->targetIds,
            );
        } catch (Throwable) {
            return false;
        }

        return $canonical->targetIds === $contract->targetIds;
    }

    private function pollingContractIsCanonical(PollingContract $contract): bool
    {
        try {
            new PollingContract(
                $contract->maximumAttempts,
                $contract->deadlineSeconds,
                $contract->minimumIntervalSeconds,
                $contract->maximumIntervalSeconds,
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return list<string> */
    private function reconciliationResultValues(AuthoritativeOperationDefinition $definition): array
    {
        return array_map(
            static fn (AuthoritativeReconciliationResult $result): string => $result->value,
            $definition->reconciliationResults,
        );
    }

    /** @return list<string> */
    private function canonicalReconciliationResultValues(): array
    {
        $values = array_map(
            static fn (AuthoritativeReconciliationResult $result): string => $result->value,
            AuthoritativeReconciliationResult::cases(),
        );
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  list<TerminalProofKind>  $proofKinds
     * @return list<string>
     */
    private function proofKindValues(array $proofKinds): array
    {
        $values = $this->proofKindValuesWithoutSorting($proofKinds);
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param  list<TerminalProofKind>  $proofKinds
     * @return list<string>
     */
    private function proofKindValuesWithoutSorting(array $proofKinds): array
    {
        return array_map(
            static fn (TerminalProofKind $proofKind): string => $proofKind->value,
            $proofKinds,
        );
    }

    /** @param list<string> $values */
    private function stringListIsCanonicallyOrdered(array $values): bool
    {
        $canonical = $values;
        sort($canonical, SORT_STRING);

        return $values === $canonical;
    }

    private function terminalKey(
        OperationStatus $status,
        EffectState $effectState,
        ResultAvailability $resultAvailability,
    ): string {
        return "{$status->value}|{$effectState->value}|{$resultAvailability->value}";
    }
}
