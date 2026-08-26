<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\InitialOperationLane;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\SuccessEffectPolicy;
use Cieplik206\IntegrationOperations\Exceptions\InvalidStateTransition;
use Cieplik206\IntegrationOperations\Runtime\AuthoritativeOperationStateMachine;

it('selects the initial durable lane from the frozen definition', function (): void {
    $machine = new AuthoritativeOperationStateMachine;

    expect($machine->initial(InitialOperationLane::Execute)->toStatus)->toBe(OperationStatus::Pending)
        ->and($machine->initial(InitialOperationLane::Poll)->toStatus)->toBe(OperationStatus::PollWait)
        ->and($machine->initial(InitialOperationLane::Poll)->toEffectState)->toBe(EffectState::NotStarted);
});

it('uses the typed poll lane without consuming reconciliation transitions', function (): void {
    $machine = new AuthoritativeOperationStateMachine;
    $polling = $machine->transition(
        OperationStatus::PollWait,
        EffectState::NotStarted,
        OperationStatus::Polling,
        EffectState::NotStarted,
        1,
        SuccessEffectPolicy::MayBeObservedExternally,
    );
    $waiting = $machine->transition(
        $polling->toStatus,
        $polling->toEffectState,
        OperationStatus::PollWait,
        EffectState::NotStarted,
        1,
        SuccessEffectPolicy::MayBeObservedExternally,
    );
    $sendRequired = $machine->transition(
        OperationStatus::Polling,
        EffectState::NotStarted,
        OperationStatus::Pending,
        EffectState::NotStarted,
        1,
        SuccessEffectPolicy::MayBeObservedExternally,
    );

    expect($waiting->toStatus)->toBe(OperationStatus::PollWait)
        ->and($sendRequired->toStatus)->toBe(OperationStatus::Pending);
});

it('moves a durable send acknowledgement and recovered acknowledgement into polling', function (OperationStatus $status): void {
    $transition = (new AuthoritativeOperationStateMachine)->transition(
        $status,
        EffectState::PossiblyApplied,
        OperationStatus::PollWait,
        EffectState::Applied,
        1,
        SuccessEffectPolicy::MayBeObservedExternally,
    );

    expect($transition->toStatus)->toBe(OperationStatus::PollWait)
        ->and($transition->toEffectState)->toBe(EffectState::Applied);
})->with([OperationStatus::Processing, OperationStatus::Reconciling]);

it('resumes manual review through a schedulable state instead of fabricating a lease', function (): void {
    $machine = new AuthoritativeOperationStateMachine;

    expect($machine->transition(
        OperationStatus::ManualReview,
        EffectState::PossiblyApplied,
        OperationStatus::Uncertain,
        EffectState::PossiblyApplied,
        1,
        SuccessEffectPolicy::MustBeAppliedByOperation,
    )->toStatus)->toBe(OperationStatus::Uncertain)
        ->and($machine->transition(
            OperationStatus::ManualReview,
            EffectState::Applied,
            OperationStatus::PollWait,
            EffectState::Applied,
            1,
            SuccessEffectPolicy::MayBeObservedExternally,
        )->toStatus)->toBe(OperationStatus::PollWait)
        ->and(fn () => $machine->transition(
            OperationStatus::ManualReview,
            EffectState::PossiblyApplied,
            OperationStatus::Reconciling,
            EffectState::PossiblyApplied,
            1,
            SuccessEffectPolicy::MustBeAppliedByOperation,
        ))->toThrow(InvalidStateTransition::class);
});

it('derives successful effect shape from the frozen success policy', function (): void {
    $machine = new AuthoritativeOperationStateMachine;

    expect($machine->transition(
        OperationStatus::Polling,
        EffectState::NotStarted,
        OperationStatus::Succeeded,
        EffectState::NotStarted,
        1,
        SuccessEffectPolicy::MayBeObservedExternally,
    )->toEffectState)->toBe(EffectState::NotStarted)
        ->and($machine->transition(
            OperationStatus::Polling,
            EffectState::Applied,
            OperationStatus::Succeeded,
            EffectState::Applied,
            1,
            SuccessEffectPolicy::MayBeObservedExternally,
        )->toEffectState)->toBe(EffectState::Applied)
        ->and(fn () => $machine->transition(
            OperationStatus::Polling,
            EffectState::NotStarted,
            OperationStatus::Succeeded,
            EffectState::NotStarted,
            1,
            SuccessEffectPolicy::MustBeAppliedByOperation,
        ))->toThrow(InvalidStateTransition::class);
});

it('allows failed applied only from already-applied durable evidence', function (): void {
    $machine = new AuthoritativeOperationStateMachine;

    expect($machine->transition(
        OperationStatus::Polling,
        EffectState::Applied,
        OperationStatus::Failed,
        EffectState::Applied,
        1,
        SuccessEffectPolicy::MayBeObservedExternally,
    )->toEffectState)->toBe(EffectState::Applied)
        ->and(fn () => $machine->transition(
            OperationStatus::Polling,
            EffectState::NotStarted,
            OperationStatus::Failed,
            EffectState::Applied,
            1,
            SuccessEffectPolicy::MayBeObservedExternally,
        ))->toThrow(InvalidStateTransition::class);
});

it('forbids possibly-applied poll states and terminal states', function (OperationStatus $status): void {
    expect(fn () => (new AuthoritativeOperationStateMachine)->transition(
        OperationStatus::Processing,
        EffectState::PossiblyApplied,
        $status,
        EffectState::PossiblyApplied,
        1,
        SuccessEffectPolicy::MayBeObservedExternally,
    ))->toThrow(InvalidStateTransition::class);
})->with([
    OperationStatus::PollWait,
    OperationStatus::Polling,
    OperationStatus::Succeeded,
    OperationStatus::Failed,
]);
