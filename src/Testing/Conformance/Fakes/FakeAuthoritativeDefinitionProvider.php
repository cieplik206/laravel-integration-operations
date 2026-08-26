<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance\Fakes;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjector;
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
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\CompensationContract;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\PollingContract;
use Cieplik206\IntegrationOperations\Registry\ProjectionContract;
use Cieplik206\IntegrationOperations\Registry\ResultEnvelopeDescriptor;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomeContract;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\Registry\WriteActivationContract;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

final class FakeAuthoritativeDefinitionProvider implements AuthoritativeOperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return new ProviderKey('fixture_authoritative');
    }

    public static function definitions(): iterable
    {
        $extensions = FakeAuthoritativeProviderExtensions::class;
        $resultEnvelope = new ResultEnvelopeDescriptor(
            new ServiceReference($extensions, OperationResultCodec::class),
            FakeAuthoritativeProviderExtensions::resultType(),
            FakeAuthoritativeProviderExtensions::schemaVersion(),
            4_096,
            ResultEnvelopeDescriptor::minimumAesGcmCiphertextBytes(4_096),
        );

        yield new AuthoritativeOperationDefinition(
            provider: self::provider(),
            operationType: new OperationType('fixture_authoritative.resource.read'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            maximumRemoteWrites: 0,
            managedMutationIdentity: null,
            boundaryMode: BoundaryMode::Forbidden,
            initialLane: InitialOperationLane::Execute,
            successEffectPolicy: SuccessEffectPolicy::ReadOnly,
            writeActivation: WriteActivationContract::disabled('default'),
            polling: null,
            retryMode: RetryMode::ReadSafe,
            safeRetryEvidence: ['definitive_transient_read', 'request_not_started'],
            ambiguousEffectAction: AmbiguousEffectAction::NotApplicable,
            reconciliationResults: [],
            terminalOutcomes: new TerminalOutcomeContract([
                new TerminalOutcomePair(
                    OperationStatus::Cancelled,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator, TerminalProofKind::PreEffect],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::NotStarted,
                    ResultAvailability::Available,
                    [TerminalProofKind::Execute],
                ),
            ]),
            resultEnvelope: $resultEnvelope,
            transportTargets: [],
            projection: new ProjectionContract(
                new ServiceReference($extensions, OutcomeProjectionPlanner::class),
                1,
                [],
            ),
            observationProjection: null,
            compensations: [],
            payloadCodec: new ServiceReference($extensions, OperationPayloadCodec::class),
            handler: new ServiceReference($extensions, OperationHandler::class),
            failureClassifier: new ServiceReference($extensions, AuthoritativeFailureClassifier::class),
            retryPolicy: new ServiceReference($extensions, AuthoritativeRetryPolicy::class),
            reconciliationStrategy: null,
            pollingStrategy: null,
        );

        $pollingExtensions = FakeAuthoritativePollingExtensions::class;

        yield new AuthoritativeOperationDefinition(
            provider: self::provider(),
            operationType: new OperationType('fixture_authoritative.resource.ensure'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            maximumRemoteWrites: 1,
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'fixture_resource',
                localReferenceType: 'fixture_resource',
                semanticSlots: ['poll'],
            ),
            boundaryMode: BoundaryMode::Optional,
            initialLane: InitialOperationLane::Poll,
            successEffectPolicy: SuccessEffectPolicy::MayBeObservedExternally,
            writeActivation: new WriteActivationContract([
                'default' => WriteActivation::PollSendRequired,
            ]),
            polling: new PollingContract(3, 60, 1, 10),
            retryMode: RetryMode::EffectAware,
            safeRetryEvidence: ['request_not_started'],
            ambiguousEffectAction: AmbiguousEffectAction::Reconcile,
            reconciliationResults: AuthoritativeReconciliationResult::cases(),
            terminalOutcomes: new TerminalOutcomeContract([
                new TerminalOutcomePair(
                    OperationStatus::Cancelled,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Poll, TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotApplied,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotStarted,
                    ResultAvailability::Available,
                    [TerminalProofKind::Poll, TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator, TerminalProofKind::PreEffect],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Execute, TerminalProofKind::Poll, TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::NotStarted,
                    ResultAvailability::Available,
                    [TerminalProofKind::Poll],
                ),
            ]),
            resultEnvelope: $resultEnvelope,
            transportTargets: [],
            projection: new ProjectionContract(
                new ServiceReference($extensions, OutcomeProjectionPlanner::class),
                1,
                [],
            ),
            observationProjection: new ProjectionContract(
                new ServiceReference($pollingExtensions, ObservationProjectionPlanner::class),
                1,
                ['fixture_authoritative.observation'],
            ),
            compensations: [new CompensationContract(
                new OperationType('fixture_authoritative.resource.ensure'),
                'reverse',
                new OperationType('fixture_authoritative.resource.reverse'),
                [new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Execute],
                )],
            )],
            payloadCodec: new ServiceReference($extensions, OperationPayloadCodec::class),
            handler: new ServiceReference($extensions, OperationHandler::class),
            failureClassifier: new ServiceReference($extensions, AuthoritativeFailureClassifier::class),
            retryPolicy: new ServiceReference($extensions, AuthoritativeRetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                $pollingExtensions,
                AuthoritativeReconciliationStrategy::class,
            ),
            pollingStrategy: new ServiceReference($pollingExtensions, PollingStrategy::class),
            observationProjector: new ServiceReference($pollingExtensions, ObservationProjector::class),
        );

        yield new AuthoritativeOperationDefinition(
            provider: self::provider(),
            operationType: new OperationType('fixture_authoritative.resource.reverse'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            maximumRemoteWrites: 1,
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'fixture_resource',
                localReferenceType: 'fixture_resource',
                semanticSlots: ['reverse'],
            ),
            boundaryMode: BoundaryMode::Required,
            initialLane: InitialOperationLane::Execute,
            successEffectPolicy: SuccessEffectPolicy::MustBeAppliedByOperation,
            writeActivation: new WriteActivationContract([
                'default' => WriteActivation::ImmediateExecute,
            ]),
            polling: null,
            retryMode: RetryMode::EffectAware,
            safeRetryEvidence: ['request_not_started'],
            ambiguousEffectAction: AmbiguousEffectAction::Reconcile,
            reconciliationResults: AuthoritativeReconciliationResult::cases(),
            terminalOutcomes: new TerminalOutcomeContract([
                new TerminalOutcomePair(
                    OperationStatus::Cancelled,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotApplied,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator, TerminalProofKind::PreEffect],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Execute, TerminalProofKind::Reconcile],
                ),
            ]),
            resultEnvelope: $resultEnvelope,
            transportTargets: [],
            projection: new ProjectionContract(
                new ServiceReference($extensions, OutcomeProjectionPlanner::class),
                1,
                [],
            ),
            observationProjection: new ProjectionContract(
                new ServiceReference($pollingExtensions, ObservationProjectionPlanner::class),
                1,
                ['fixture_authoritative.observation'],
            ),
            compensations: [],
            payloadCodec: new ServiceReference($extensions, OperationPayloadCodec::class),
            handler: new ServiceReference($extensions, OperationHandler::class),
            failureClassifier: new ServiceReference($extensions, AuthoritativeFailureClassifier::class),
            retryPolicy: new ServiceReference($extensions, AuthoritativeRetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                $pollingExtensions,
                AuthoritativeReconciliationStrategy::class,
            ),
            pollingStrategy: null,
            observationProjector: new ServiceReference($pollingExtensions, ObservationProjector::class),
        );
    }
}
