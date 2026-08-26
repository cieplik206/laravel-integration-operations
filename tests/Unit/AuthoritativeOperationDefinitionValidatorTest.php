<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\PollingContext;
use Cieplik206\IntegrationOperations\Contracts\PollingStrategy;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
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
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\CompensationContract;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\InvalidOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\PollingContract;
use Cieplik206\IntegrationOperations\Registry\ProjectionContract;
use Cieplik206\IntegrationOperations\Registry\ResultEnvelopeDescriptor;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomeContract;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\Registry\TransportTargetDefinition;
use Cieplik206\IntegrationOperations\Registry\WriteActivationContract;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;
use Illuminate\Container\Container;

final class AuthoritativeValidatorPayloadCodec implements OperationPayloadCodec
{
    public static function schemaVersion(): int
    {
        return 1;
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $payload;
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        return 'default';
    }
}

final class AuthoritativeValidatorWrongPayloadCodec implements OperationPayloadCodec
{
    public static function schemaVersion(): int
    {
        return 2;
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $payload;
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        return 'default';
    }
}

final class AuthoritativeValidatorOutOfBoundsPayloadCodec implements OperationPayloadCodec
{
    public static function schemaVersion(): int
    {
        return 65_536;
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $payload;
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        return 'default';
    }
}

final class AuthoritativeValidatorHandler implements OperationHandler
{
    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        throw new LogicException('The validator fixture handler is never executed.');
    }
}

final class AuthoritativeValidatorFailureClassifier implements AuthoritativeFailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): ClassifiedFailure
    {
        throw new LogicException('The validator fixture classifier is never executed.');
    }
}

final class AuthoritativeValidatorRetryPolicy implements AuthoritativeRetryPolicy
{
    public function decide(OperationView $operation, ClassifiedFailure $failure): RetryInstruction
    {
        throw new LogicException('The validator fixture retry policy is never executed.');
    }
}

final class AuthoritativeValidatorReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome
    {
        throw new LogicException('The validator fixture reconciliation is never executed.');
    }
}

final class AuthoritativeValidatorPollingStrategy implements PollingStrategy
{
    public function poll(PollingContext $context): PollOutcome
    {
        throw new LogicException('The validator fixture polling is never executed.');
    }
}

final class AuthoritativeValidatorResultCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'fixture.authoritative_result';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        throw new LogicException('The validator fixture result codec is never executed.');
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The validator fixture result codec is never executed.');
    }
}

final class AuthoritativeValidatorWrongResultCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'fixture.other_result';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        throw new LogicException('The validator fixture result codec is never executed.');
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The validator fixture result codec is never executed.');
    }
}

final class AuthoritativeValidatorOutOfBoundsResultCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'INVALID';
    }

    public static function schemaVersion(): int
    {
        return 65_536;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        throw new LogicException('The validator fixture result codec is never executed.');
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new LogicException('The validator fixture result codec is never executed.');
    }
}

final class AuthoritativeValidatorOutcomePlanner implements OutcomeProjectionPlanner
{
    public function plan(ProjectionInput $input): ProjectionPlan
    {
        throw new LogicException('The validator fixture planner is never executed.');
    }
}

final class AuthoritativeValidatorObservationPlanner implements ObservationProjectionPlanner
{
    public function plan(ObservationProjectionInput $input): ObservationProjectionPlan
    {
        throw new LogicException('The validator fixture planner is never executed.');
    }
}

final class AuthoritativeDefinitionFixture
{
    public ProviderKey $provider;

    public OperationType $operationType;

    public OperationDefinitionVersions $versions;

    public int $maximumRemoteWrites = 0;

    public ?ManagedMutationIdentityContract $managedMutationIdentity = null;

    public BoundaryMode $boundaryMode = BoundaryMode::Forbidden;

    public InitialOperationLane $initialLane = InitialOperationLane::Execute;

    public SuccessEffectPolicy $successEffectPolicy = SuccessEffectPolicy::ReadOnly;

    public WriteActivationContract $writeActivation;

    public ?PollingContract $polling = null;

    public RetryMode $retryMode = RetryMode::ReadSafe;

    /** @var list<string> */
    public array $safeRetryEvidence = ['definitive_transient_read', 'request_not_started'];

    public AmbiguousEffectAction $ambiguousEffectAction = AmbiguousEffectAction::NotApplicable;

    /** @var list<AuthoritativeReconciliationResult> */
    public array $reconciliationResults = [];

    public TerminalOutcomeContract $terminalOutcomes;

    public ResultEnvelopeDescriptor $resultEnvelope;

    /** @var list<TransportTargetDefinition> */
    public array $transportTargets = [];

    public ProjectionContract $projection;

    public ?ProjectionContract $observationProjection = null;

    /** @var list<CompensationContract> */
    public array $compensations = [];

    public ServiceReference $payloadCodec;

    public ServiceReference $handler;

    public ServiceReference $failureClassifier;

    public ServiceReference $retryPolicy;

    public ?ServiceReference $reconciliationStrategy = null;

    public ?ServiceReference $pollingStrategy = null;

    public function __construct()
    {
        $this->provider = new ProviderKey('fixture');
        $this->operationType = new OperationType('fixture.resource.read');
        $this->versions = new OperationDefinitionVersions(1, 1, 1);
        $this->writeActivation = WriteActivationContract::disabled('default');
        $this->terminalOutcomes = authoritativeReadTerminalOutcomes();
        $this->payloadCodec = new ServiceReference(
            AuthoritativeValidatorPayloadCodec::class,
            OperationPayloadCodec::class,
        );
        $this->handler = new ServiceReference(
            AuthoritativeValidatorHandler::class,
            OperationHandler::class,
        );
        $this->failureClassifier = new ServiceReference(
            AuthoritativeValidatorFailureClassifier::class,
            AuthoritativeFailureClassifier::class,
        );
        $this->retryPolicy = new ServiceReference(
            AuthoritativeValidatorRetryPolicy::class,
            AuthoritativeRetryPolicy::class,
        );
        $resultCodec = new ServiceReference(
            AuthoritativeValidatorResultCodec::class,
            OperationResultCodec::class,
        );
        $this->resultEnvelope = new ResultEnvelopeDescriptor(
            $resultCodec,
            AuthoritativeValidatorResultCodec::resultType(),
            1,
            4_096,
            8_192,
        );
        $this->projection = new ProjectionContract(
            new ServiceReference(
                AuthoritativeValidatorOutcomePlanner::class,
                OutcomeProjectionPlanner::class,
            ),
            1,
            [],
        );
    }

    public function asImmediateWrite(bool $withReconciliation = true, bool $withPolling = false): self
    {
        $this->maximumRemoteWrites = 1;
        $this->managedMutationIdentity = new ManagedMutationIdentityContract(
            'fixture_resource',
            'fixture_reference',
            ['default'],
        );
        $this->boundaryMode = BoundaryMode::Required;
        $this->initialLane = InitialOperationLane::Execute;
        $this->successEffectPolicy = SuccessEffectPolicy::MustBeAppliedByOperation;
        $this->writeActivation = WriteActivationContract::immediate('default');
        $this->retryMode = RetryMode::EffectAware;
        $this->safeRetryEvidence = ['request_not_started'];
        $this->configureReconciliation($withReconciliation);
        $this->configurePolling($withPolling);
        $this->terminalOutcomes = authoritativeImmediateTerminalOutcomes($withReconciliation, $withPolling);

        return $this;
    }

    public function asPollActivatedWrite(bool $withReconciliation = true): self
    {
        $this->maximumRemoteWrites = 1;
        $this->managedMutationIdentity = new ManagedMutationIdentityContract(
            'fixture_resource',
            'fixture_reference',
            ['automatic', 'explicit'],
        );
        $this->boundaryMode = BoundaryMode::Optional;
        $this->initialLane = InitialOperationLane::Poll;
        $this->successEffectPolicy = SuccessEffectPolicy::MayBeObservedExternally;
        $this->writeActivation = new WriteActivationContract([
            'automatic' => WriteActivation::Disabled,
            'explicit' => WriteActivation::PollSendRequired,
        ]);
        $this->retryMode = RetryMode::EffectAware;
        $this->safeRetryEvidence = ['request_not_started'];
        $this->configureReconciliation($withReconciliation);
        $this->configurePolling(true);
        $this->terminalOutcomes = authoritativePollTerminalOutcomes($withReconciliation);

        return $this;
    }

    public function build(): AuthoritativeOperationDefinition
    {
        return new AuthoritativeOperationDefinition(
            provider: $this->provider,
            operationType: $this->operationType,
            versions: $this->versions,
            maximumRemoteWrites: $this->maximumRemoteWrites,
            managedMutationIdentity: $this->managedMutationIdentity,
            boundaryMode: $this->boundaryMode,
            initialLane: $this->initialLane,
            successEffectPolicy: $this->successEffectPolicy,
            writeActivation: $this->writeActivation,
            polling: $this->polling,
            retryMode: $this->retryMode,
            safeRetryEvidence: $this->safeRetryEvidence,
            ambiguousEffectAction: $this->ambiguousEffectAction,
            reconciliationResults: $this->reconciliationResults,
            terminalOutcomes: $this->terminalOutcomes,
            resultEnvelope: $this->resultEnvelope,
            transportTargets: $this->transportTargets,
            projection: $this->projection,
            observationProjection: $this->observationProjection,
            compensations: $this->compensations,
            payloadCodec: $this->payloadCodec,
            handler: $this->handler,
            failureClassifier: $this->failureClassifier,
            retryPolicy: $this->retryPolicy,
            reconciliationStrategy: $this->reconciliationStrategy,
            pollingStrategy: $this->pollingStrategy,
        );
    }

    private function configureReconciliation(bool $enabled): void
    {
        $this->ambiguousEffectAction = $enabled
            ? AmbiguousEffectAction::Reconcile
            : AmbiguousEffectAction::ManualReview;
        $this->reconciliationResults = $enabled
            ? AuthoritativeReconciliationResult::cases()
            : [];
        $this->reconciliationStrategy = $enabled
            ? new ServiceReference(
                AuthoritativeValidatorReconciliationStrategy::class,
                AuthoritativeReconciliationStrategy::class,
            )
            : null;
    }

    private function configurePolling(bool $enabled): void
    {
        $this->polling = $enabled ? new PollingContract(33, 86_400, 1, 3_600) : null;
        $this->pollingStrategy = $enabled
            ? new ServiceReference(
                AuthoritativeValidatorPollingStrategy::class,
                PollingStrategy::class,
            )
            : null;
        $this->observationProjection = $enabled
            ? new ProjectionContract(
                new ServiceReference(
                    AuthoritativeValidatorObservationPlanner::class,
                    ObservationProjectionPlanner::class,
                ),
                1,
                [],
            )
            : null;
    }
}

final class AuthoritativeValidatorDefinitionProvider implements AuthoritativeOperationDefinitionProvider
{
    /** @var list<AuthoritativeOperationDefinition> */
    public static array $registeredDefinitions = [];

    public static function provider(): ProviderKey
    {
        return new ProviderKey('fixture');
    }

    public static function definitions(): iterable
    {
        return self::$registeredDefinitions;
    }
}

/**
 * @template TObject of object
 *
 * @param  TObject  $source
 * @param  array<string, mixed>  $overrides
 * @return TObject
 */
function authoritativeForgeReadonlyObject(object $source, array $overrides): object
{
    $reflection = new ReflectionClass($source);
    $forged = $reflection->newInstanceWithoutConstructor();

    foreach ($reflection->getProperties() as $property) {
        $name = $property->getName();
        $value = array_key_exists($name, $overrides)
            ? $overrides[$name]
            : $property->getValue($source);
        $property->setValue($forged, $value);
    }

    /** @var TObject $forged */
    return $forged;
}

function authoritativeValidatorBindingInspector(): ContainerBindingInspector
{
    $container = new Container;

    foreach ([
        AuthoritativeValidatorPayloadCodec::class,
        AuthoritativeValidatorHandler::class,
        AuthoritativeValidatorFailureClassifier::class,
        AuthoritativeValidatorRetryPolicy::class,
        AuthoritativeValidatorReconciliationStrategy::class,
        AuthoritativeValidatorPollingStrategy::class,
        AuthoritativeValidatorResultCodec::class,
        AuthoritativeValidatorOutcomePlanner::class,
        AuthoritativeValidatorObservationPlanner::class,
    ] as $service) {
        $container->singleton($service, $service);
    }

    return new ContainerBindingInspector($container);
}

/** @param list<AuthoritativeOperationDefinition> $definitions */
function authoritativeRegistryWith(array $definitions): AuthoritativeDefinitionRegistry
{
    AuthoritativeValidatorDefinitionProvider::$registeredDefinitions = $definitions;
    $registry = new AuthoritativeDefinitionRegistry;
    $registry->register(AuthoritativeValidatorDefinitionProvider::class);

    return $registry;
}

function authoritativeImmediateDefinition(string $operationType): AuthoritativeOperationDefinition
{
    $fixture = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $fixture->operationType = new OperationType($operationType);

    return $fixture->build();
}

/** @param list<TerminalOutcomePair> $allowedOutcomes */
function authoritativeParentWithCompensation(
    string $childType,
    array $allowedOutcomes,
    bool $withPolling = false,
): AuthoritativeOperationDefinition {
    $fixture = (new AuthoritativeDefinitionFixture)->asImmediateWrite(withPolling: $withPolling);
    $fixture->operationType = new OperationType('fixture.resource.write');
    $fixture->compensations = [new CompensationContract(
        $fixture->operationType,
        'reverse',
        new OperationType($childType),
        $allowedOutcomes,
    )];

    return $fixture->build();
}

/** @param list<TerminalProofKind> $proofKinds */
function authoritativeTerminalPair(
    OperationStatus $status,
    EffectState $effectState,
    ResultAvailability $resultAvailability,
    array $proofKinds,
): TerminalOutcomePair {
    return new TerminalOutcomePair($status, $effectState, $resultAvailability, $proofKinds);
}

/** @return list<TerminalOutcomePair> */
function authoritativeCommonTerminalPairs(): array
{
    return [
        authoritativeTerminalPair(
            OperationStatus::Failed,
            EffectState::NotStarted,
            ResultAvailability::NotApplicable,
            [TerminalProofKind::Operator, TerminalProofKind::PreEffect],
        ),
        authoritativeTerminalPair(
            OperationStatus::Cancelled,
            EffectState::NotStarted,
            ResultAvailability::NotApplicable,
            [TerminalProofKind::Operator],
        ),
    ];
}

function authoritativeReadTerminalOutcomes(): TerminalOutcomeContract
{
    return new TerminalOutcomeContract([
        ...authoritativeCommonTerminalPairs(),
        authoritativeTerminalPair(
            OperationStatus::Succeeded,
            EffectState::NotStarted,
            ResultAvailability::Available,
            [TerminalProofKind::Execute],
        ),
    ]);
}

function authoritativeImmediateTerminalOutcomes(
    bool $withReconciliation,
    bool $withPolling,
): TerminalOutcomeContract {
    $pairs = authoritativeCommonTerminalPairs();
    $successProofKinds = [TerminalProofKind::Execute];
    $providerRejectionProofKinds = [];

    if ($withPolling) {
        $successProofKinds[] = TerminalProofKind::Poll;
        $providerRejectionProofKinds[] = TerminalProofKind::Poll;
    }

    if ($withReconciliation) {
        $successProofKinds[] = TerminalProofKind::Reconcile;
        $providerRejectionProofKinds[] = TerminalProofKind::Reconcile;
        $pairs[] = authoritativeTerminalPair(
            OperationStatus::Failed,
            EffectState::NotApplied,
            ResultAvailability::NotApplicable,
            [TerminalProofKind::Reconcile],
        );
    }

    $pairs[] = authoritativeTerminalPair(
        OperationStatus::Succeeded,
        EffectState::Applied,
        ResultAvailability::Available,
        $successProofKinds,
    );

    if ($providerRejectionProofKinds !== []) {
        $pairs[] = authoritativeTerminalPair(
            OperationStatus::Failed,
            EffectState::Applied,
            ResultAvailability::Available,
            $providerRejectionProofKinds,
        );
    }

    return new TerminalOutcomeContract($pairs);
}

function authoritativePollTerminalOutcomes(bool $withReconciliation): TerminalOutcomeContract
{
    $pairs = authoritativeCommonTerminalPairs();
    $successProofKinds = [TerminalProofKind::Execute, TerminalProofKind::Poll];
    $providerRejectionProofKinds = [TerminalProofKind::Poll];
    $preWriteFailureProofKinds = [TerminalProofKind::Poll];

    if ($withReconciliation) {
        $successProofKinds[] = TerminalProofKind::Reconcile;
        $providerRejectionProofKinds[] = TerminalProofKind::Reconcile;
        $preWriteFailureProofKinds[] = TerminalProofKind::Reconcile;
        $pairs[] = authoritativeTerminalPair(
            OperationStatus::Failed,
            EffectState::NotApplied,
            ResultAvailability::NotApplicable,
            [TerminalProofKind::Reconcile],
        );
    }

    $pairs[] = authoritativeTerminalPair(
        OperationStatus::Succeeded,
        EffectState::NotStarted,
        ResultAvailability::Available,
        [TerminalProofKind::Poll],
    );
    $pairs[] = authoritativeTerminalPair(
        OperationStatus::Failed,
        EffectState::NotStarted,
        ResultAvailability::Available,
        $preWriteFailureProofKinds,
    );
    $pairs[] = authoritativeTerminalPair(
        OperationStatus::Succeeded,
        EffectState::Applied,
        ResultAvailability::Available,
        $successProofKinds,
    );
    $pairs[] = authoritativeTerminalPair(
        OperationStatus::Failed,
        EffectState::Applied,
        ResultAvailability::Available,
        $providerRejectionProofKinds,
    );

    return new TerminalOutcomeContract($pairs);
}

it('accepts only exact read, immediate-write, and poll-activated profiles', function (): void {
    $validator = new AuthoritativeOperationDefinitionValidator;

    expect($validator->violations((new AuthoritativeDefinitionFixture)->build()))->toBe([])
        ->and($validator->violations((new AuthoritativeDefinitionFixture)->asImmediateWrite()->build()))->toBe([])
        ->and($validator->violations((new AuthoritativeDefinitionFixture)->asImmediateWrite(false)->build()))->toBe([])
        ->and($validator->violations((new AuthoritativeDefinitionFixture)->asImmediateWrite(withPolling: true)->build()))->toBe([])
        ->and($validator->violations((new AuthoritativeDefinitionFixture)->asPollActivatedWrite()->build()))->toBe([]);
});

it('rejects mismatched identity, durable versions, exact service references, and static codec metadata', function (): void {
    $validator = new AuthoritativeOperationDefinitionValidator;
    $identity = new AuthoritativeDefinitionFixture;
    $identity->provider = new ProviderKey('other');
    $versions = new AuthoritativeDefinitionFixture;
    $versions->versions = new OperationDefinitionVersions(65_536, 1, 1);
    $handler = new AuthoritativeDefinitionFixture;
    $handler->handler = new ServiceReference(
        AuthoritativeValidatorRetryPolicy::class,
        AuthoritativeRetryPolicy::class,
    );
    $payload = new AuthoritativeDefinitionFixture;
    $payload->payloadCodec = new ServiceReference(
        AuthoritativeValidatorWrongPayloadCodec::class,
        OperationPayloadCodec::class,
    );
    $payloadBounds = new AuthoritativeDefinitionFixture;
    $payloadBounds->payloadCodec = new ServiceReference(
        AuthoritativeValidatorOutOfBoundsPayloadCodec::class,
        OperationPayloadCodec::class,
    );
    $result = new AuthoritativeDefinitionFixture;
    $wrongResultCodec = new ServiceReference(
        AuthoritativeValidatorWrongResultCodec::class,
        OperationResultCodec::class,
    );
    $result->resultEnvelope = new ResultEnvelopeDescriptor(
        $wrongResultCodec,
        AuthoritativeValidatorResultCodec::resultType(),
        1,
        4_096,
        8_192,
    );
    $resultBounds = new AuthoritativeDefinitionFixture;
    $outOfBoundsResultCodec = new ServiceReference(
        AuthoritativeValidatorOutOfBoundsResultCodec::class,
        OperationResultCodec::class,
    );
    $resultBounds->resultEnvelope = new ResultEnvelopeDescriptor(
        $outOfBoundsResultCodec,
        AuthoritativeValidatorResultCodec::resultType(),
        1,
        4_096,
        8_192,
    );

    expect($validator->violations($identity->build()))
        ->toContain('operation type does not have the provider prefix')
        ->and($validator->violations($versions->build()))
        ->toContain('authoritative definition versions exceed durable storage bounds')
        ->and($validator->violations($handler->build()))
        ->toContain('extension point operation_handler targets an incompatible contract')
        ->and($validator->violations($payload->build()))
        ->toContain('payload codec schema version does not match the definition')
        ->and($validator->violations($payloadBounds->build()))
        ->toContain('payload codec schema version is outside durable bounds')
        ->and($validator->violations($result->build()))
        ->toContain('result codec schema version does not match the result envelope')
        ->toContain('result codec type does not match the result envelope')
        ->and($validator->violations($resultBounds->build()))
        ->toContain('result codec schema version is outside durable bounds')
        ->toContain('result codec type is outside canonical bounds');
});

it('revalidates bounded descriptors and returns deterministic canonical violations', function (): void {
    $validator = new AuthoritativeOperationDefinitionValidator;
    $target = new TransportTargetDefinition('fixture.read', 'https', 'GET', '/resources/{resource_id}');
    $forgedTarget = authoritativeForgeReadonlyObject($target, ['targetTemplate' => '/resources/x{resource_id}']);
    $transport = new AuthoritativeDefinitionFixture;
    $transport->transportTargets = [$forgedTarget];
    $resultEnvelope = new AuthoritativeDefinitionFixture;
    $resultEnvelope->resultEnvelope = authoritativeForgeReadonlyObject(
        $resultEnvelope->resultEnvelope,
        ['maximumCiphertextBytes' => ResultEnvelopeDescriptor::HardMaximumCiphertextBytes + 1],
    );
    $projection = new AuthoritativeDefinitionFixture;
    $projection->projection = authoritativeForgeReadonlyObject(
        $projection->projection,
        ['schemaVersion' => 65_536],
    );
    $ordering = (new AuthoritativeDefinitionFixture)->asPollActivatedWrite();
    $ordering->managedMutationIdentity = new ManagedMutationIdentityContract(
        'fixture_resource',
        'fixture_reference',
        ['explicit', 'automatic'],
    );
    $multiple = new AuthoritativeDefinitionFixture;
    $multiple->provider = new ProviderKey('other');
    $multiple->versions = new OperationDefinitionVersions(65_536, 65_536, 65_536);
    $violations = $validator->violations($multiple->build());
    $sortedViolations = $violations;
    sort($sortedViolations, SORT_STRING);

    expect($validator->violations($transport->build()))
        ->toContain('transport target fixture.read is outside canonical bounds')
        ->and($validator->violations($resultEnvelope->build()))
        ->toContain('result envelope descriptor is outside canonical bounds')
        ->and($validator->violations($projection->build()))
        ->toContain('outcome projection contract is outside canonical bounds')
        ->and($validator->violations($ordering->build()))
        ->toContain('authoritative definition collections must use deterministic canonical ordering')
        ->and($violations)->toBe($sortedViolations);
});

it('canonicalizes transport projection and compensation collections independently of input order', function (): void {
    $fixture = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $fixture->transportTargets = [
        new TransportTargetDefinition('fixture.second', 'https', 'GET', '/second'),
        new TransportTargetDefinition('fixture.first', 'https', 'GET', '/first/{resource_id}'),
    ];
    $fixture->projection = new ProjectionContract(
        new ServiceReference(AuthoritativeValidatorOutcomePlanner::class, OutcomeProjectionPlanner::class),
        1,
        ['fixture.second', 'fixture.first'],
    );
    $fixture->compensations = [
        new CompensationContract(
            $fixture->operationType,
            'second',
            new OperationType('fixture.resource.compensate_second'),
            [authoritativeTerminalPair(
                OperationStatus::Succeeded,
                EffectState::Applied,
                ResultAvailability::Available,
                [TerminalProofKind::Execute],
            )],
        ),
        new CompensationContract(
            $fixture->operationType,
            'first',
            new OperationType('fixture.resource.compensate_first'),
            [authoritativeTerminalPair(
                OperationStatus::Succeeded,
                EffectState::Applied,
                ResultAvailability::Available,
                [TerminalProofKind::Execute],
            )],
        ),
    ];
    $definition = $fixture->build();

    expect(array_map(
        static fn (TransportTargetDefinition $target): string => $target->targetId,
        $definition->transportTargets,
    ))->toBe(['fixture.first', 'fixture.second'])
        ->and($definition->projection->targetIds)->toBe(['fixture.first', 'fixture.second'])
        ->and(array_map(
            static fn (CompensationContract $compensation): string => $compensation->slot,
            $definition->compensations,
        ))->toBe(['first', 'second'])
        ->and((new AuthoritativeOperationDefinitionValidator)->violations($definition))->toBe([]);
});

it('requires exact retry, activation, polling, and reconciliation vocabularies', function (): void {
    $validator = new AuthoritativeOperationDefinitionValidator;
    $readRetry = new AuthoritativeDefinitionFixture;
    $readRetry->safeRetryEvidence = ['request_not_started'];
    $pollingTuple = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $pollingTuple->polling = new PollingContract(3, 60, 1, 10);
    $reconciliationVocabulary = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $reconciliationVocabulary->reconciliationResults = [
        AuthoritativeReconciliationResult::FoundExact,
        AuthoritativeReconciliationResult::AbsentConclusive,
    ];
    $pollWithoutSend = (new AuthoritativeDefinitionFixture)->asPollActivatedWrite();
    $pollWithoutSend->writeActivation = new WriteActivationContract([
        'automatic' => WriteActivation::Disabled,
        'explicit' => WriteActivation::Disabled,
    ]);

    expect($validator->violations($readRetry->build()))
        ->toContain('read-only operation must declare the canonical read-safe retry vocabulary')
        ->and($validator->violations($pollingTuple->build()))
        ->toContain('polling contract and strategy must be declared together')
        ->toContain('polling operation must declare an observation projection')
        ->and($validator->violations($reconciliationVocabulary->build()))
        ->toContain('reconciliation-enabled operation must declare the canonical authoritative result vocabulary')
        ->and($validator->violations($pollWithoutSend->build()))
        ->toContain('single-effect poll operation must expose at least one guarded send-required slot');
});

it('validates semantic identity and write activation slots independently', function (): void {
    $fixture = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $fixture->managedMutationIdentity = new ManagedMutationIdentityContract(
        'invoice',
        'sale',
        ['vat.issue'],
    );
    $fixture->writeActivation = WriteActivationContract::immediate('create_invoice');
    $definition = $fixture->build();

    expect((new AuthoritativeOperationDefinitionValidator)->violations($definition))->toBe([])
        ->and($definition->managedMutationIdentity?->semanticSlots)->toBe(['vat.issue'])
        ->and($definition->writeActivation->requireWriteActivationSlot('create_invoice'))
        ->toBe(WriteActivation::ImmediateExecute)
        ->and(fn () => $definition->writeActivation->requireWriteActivationSlot('undeclared'))
        ->toThrow(InvalidArgumentException::class, 'Payload selected an undeclared write activation slot.');
});

it('requires the exact reachable terminal tuples and proof sets including absent failure', function (): void {
    $validator = new AuthoritativeOperationDefinitionValidator;
    $missingAbsent = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $missingAbsent->terminalOutcomes = new TerminalOutcomeContract(array_values(array_filter(
        $missingAbsent->terminalOutcomes->pairs,
        static fn (TerminalOutcomePair $pair): bool => $pair->key() !== 'failed|not_applied|not_applicable',
    )));
    $extraPollProof = new AuthoritativeDefinitionFixture;
    $extraPollProof->terminalOutcomes = new TerminalOutcomeContract([
        ...authoritativeCommonTerminalPairs(),
        authoritativeTerminalPair(
            OperationStatus::Succeeded,
            EffectState::NotStarted,
            ResultAvailability::Available,
            [TerminalProofKind::Execute, TerminalProofKind::Poll],
        ),
    ]);
    $extraTuple = new AuthoritativeDefinitionFixture;
    $extraTuple->terminalOutcomes = new TerminalOutcomeContract([
        ...$extraTuple->terminalOutcomes->pairs,
        authoritativeTerminalPair(
            OperationStatus::Failed,
            EffectState::Applied,
            ResultAvailability::Available,
            [TerminalProofKind::Reconcile],
        ),
    ]);

    expect($validator->violations($missingAbsent->build()))
        ->toContain('terminal outcome failed|not_applied|not_applicable is missing')
        ->and($validator->violations($extraPollProof->build()))
        ->toContain('terminal outcome succeeded|not_started|available declares a non-canonical proof set')
        ->and($validator->violations($extraTuple->build()))
        ->toContain('terminal outcome failed|applied|available is unreachable for the operation profile');
});

it('fails closed on sealed provider evidence until the definition exposes its frozen resolver binding', function (): void {
    $fixture = new AuthoritativeDefinitionFixture;
    $fixture->terminalOutcomes = new TerminalOutcomeContract([
        ...authoritativeCommonTerminalPairs(),
        authoritativeTerminalPair(
            OperationStatus::Succeeded,
            EffectState::NotStarted,
            ResultAvailability::Available,
            [TerminalProofKind::Execute, TerminalProofKind::SealedProviderEvidence],
        ),
    ]);

    expect((new AuthoritativeOperationDefinitionValidator)->violations($fixture->build()))
        ->toContain('sealed provider evidence proof requires a frozen resolver binding');
});

it('limits compensation eligibility to a proof-aware subset of the parent terminal contract', function (): void {
    $validator = new AuthoritativeOperationDefinitionValidator;
    $valid = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $valid->compensations = [new CompensationContract(
        $valid->operationType,
        'reverse',
        new OperationType('fixture.resource.compensate'),
        [authoritativeTerminalPair(
            OperationStatus::Succeeded,
            EffectState::Applied,
            ResultAvailability::Available,
            [TerminalProofKind::Execute],
        )],
    )];
    $invalid = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $invalid->compensations = [new CompensationContract(
        $invalid->operationType,
        'reverse',
        new OperationType('fixture.resource.compensate'),
        [authoritativeTerminalPair(
            OperationStatus::Succeeded,
            EffectState::Applied,
            ResultAvailability::Available,
            [TerminalProofKind::Poll],
        )],
    )];

    expect($validator->violations($valid->build()))->toBe([])
        ->and($validator->violations($invalid->build()))
        ->toContain('compensation slot reverse permits an outcome outside the parent terminal contract');
});

it('requires succeeded applied eligibility before an explicitly proved failed compensation outcome', function (): void {
    $parentType = new OperationType('fixture.resource.write');
    $childType = new OperationType('fixture.resource.compensate');
    $failed = authoritativeTerminalPair(
        OperationStatus::Failed,
        EffectState::Applied,
        ResultAvailability::Available,
        [TerminalProofKind::Poll],
    );

    expect(fn () => new CompensationContract($parentType, 'reverse', $childType, [$failed]))
        ->toThrow(InvalidArgumentException::class, 'requires an applied succeeded terminal outcome');

    $success = authoritativeTerminalPair(
        OperationStatus::Succeeded,
        EffectState::Applied,
        ResultAvailability::Available,
        [TerminalProofKind::Execute],
    );
    $parent = authoritativeParentWithCompensation($childType->value, [$success, $failed], withPolling: true);

    expect((new AuthoritativeOperationDefinitionValidator)->violations($parent))->toBe([]);
});

it('freezes only compensation graphs with an existing depth-one child and proof subsets of both contracts', function (): void {
    $childType = 'fixture.resource.compensate';
    $grandchildType = 'fixture.resource.compensate_nested';
    $success = authoritativeTerminalPair(
        OperationStatus::Succeeded,
        EffectState::Applied,
        ResultAvailability::Available,
        [TerminalProofKind::Execute],
    );
    $validParent = authoritativeParentWithCompensation($childType, [$success]);
    $validChild = authoritativeImmediateDefinition($childType);
    $validRegistry = authoritativeRegistryWith([$validParent, $validChild]);
    $validRegistry->freeze(authoritativeValidatorBindingInspector());

    $missingChildRegistry = authoritativeRegistryWith([$validParent]);

    $nestedChildFixture = (new AuthoritativeDefinitionFixture)->asImmediateWrite();
    $nestedChildFixture->operationType = new OperationType($childType);
    $nestedChildFixture->compensations = [new CompensationContract(
        $nestedChildFixture->operationType,
        'nested',
        new OperationType($grandchildType),
        [$success],
    )];
    $nestedRegistry = authoritativeRegistryWith([
        $validParent,
        $nestedChildFixture->build(),
        authoritativeImmediateDefinition($grandchildType),
    ]);

    $failedPoll = authoritativeTerminalPair(
        OperationStatus::Failed,
        EffectState::Applied,
        ResultAvailability::Available,
        [TerminalProofKind::Poll],
    );
    $childSubsetRegistry = authoritativeRegistryWith([
        authoritativeParentWithCompensation($childType, [$success, $failedPoll], withPolling: true),
        $validChild,
    ]);

    expect($validRegistry->isFrozen())->toBeTrue()
        ->and(fn () => $missingChildRegistry->freeze(authoritativeValidatorBindingInspector()))
        ->toThrow(InvalidOperationDefinition::class, 'compensation child definition is missing')
        ->and(fn () => $nestedRegistry->freeze(authoritativeValidatorBindingInspector()))
        ->toThrow(InvalidOperationDefinition::class, 'compensation child definition permits nested compensation')
        ->and(fn () => $childSubsetRegistry->freeze(authoritativeValidatorBindingInspector()))
        ->toThrow(InvalidOperationDefinition::class, 'compensation outcomes exceed the child terminal contract');
});

it('throws one definition exception containing the fail-closed violations', function (): void {
    $fixture = new AuthoritativeDefinitionFixture;
    $fixture->safeRetryEvidence = ['request_not_started'];

    expect(fn () => (new AuthoritativeOperationDefinitionValidator)->assertValid($fixture->build()))
        ->toThrow(InvalidOperationDefinition::class, 'canonical read-safe retry vocabulary');
});
