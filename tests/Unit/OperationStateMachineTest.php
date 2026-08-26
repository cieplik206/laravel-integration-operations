<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Exceptions\InvalidStateTransition;
use Cieplik206\IntegrationOperations\Runtime\OperationStateMachine;

$allowedStatusTransitions = [
    'pending -> processing' => [OperationStatus::Pending, OperationStatus::Processing],
    'pending -> manual review' => [OperationStatus::Pending, OperationStatus::ManualReview],
    'pending -> cancelled' => [OperationStatus::Pending, OperationStatus::Cancelled],
    'processing -> pending' => [OperationStatus::Processing, OperationStatus::Pending],
    'processing -> processing' => [OperationStatus::Processing, OperationStatus::Processing],
    'processing -> retry wait' => [OperationStatus::Processing, OperationStatus::RetryWait],
    'processing -> uncertain' => [OperationStatus::Processing, OperationStatus::Uncertain],
    'processing -> manual review' => [OperationStatus::Processing, OperationStatus::ManualReview],
    'processing -> succeeded' => [OperationStatus::Processing, OperationStatus::Succeeded],
    'processing -> failed' => [OperationStatus::Processing, OperationStatus::Failed],
    'retry wait -> pending' => [OperationStatus::RetryWait, OperationStatus::Pending],
    'retry wait -> manual review' => [OperationStatus::RetryWait, OperationStatus::ManualReview],
    'retry wait -> cancelled' => [OperationStatus::RetryWait, OperationStatus::Cancelled],
    'uncertain -> reconciling' => [OperationStatus::Uncertain, OperationStatus::Reconciling],
    'uncertain -> manual review' => [OperationStatus::Uncertain, OperationStatus::ManualReview],
    'reconciling -> uncertain' => [OperationStatus::Reconciling, OperationStatus::Uncertain],
    'reconciling -> manual review' => [OperationStatus::Reconciling, OperationStatus::ManualReview],
    'reconciling -> succeeded' => [OperationStatus::Reconciling, OperationStatus::Succeeded],
    'reconciling -> failed' => [OperationStatus::Reconciling, OperationStatus::Failed],
    'manual review -> reconciling' => [OperationStatus::ManualReview, OperationStatus::Reconciling],
    'manual review -> succeeded' => [OperationStatus::ManualReview, OperationStatus::Succeeded],
    'manual review -> failed' => [OperationStatus::ManualReview, OperationStatus::Failed],
    'manual review -> cancelled' => [OperationStatus::ManualReview, OperationStatus::Cancelled],
];

dataset('allowed operation status transitions', $allowedStatusTransitions);

$allowedStatusPairs = array_map(
    static fn (array $transition): string => "{$transition[0]->value}:{$transition[1]->value}",
    $allowedStatusTransitions,
);
$disallowedStatusTransitions = [];

foreach (OperationStatus::cases() as $fromStatus) {
    foreach (OperationStatus::cases() as $toStatus) {
        if (in_array("{$fromStatus->value}:{$toStatus->value}", $allowedStatusPairs, true)) {
            continue;
        }

        $disallowedStatusTransitions["{$fromStatus->value} -> {$toStatus->value}"] = [$fromStatus, $toStatus];
    }
}

dataset('disallowed operation status transitions', $disallowedStatusTransitions);

$allowedEffectTransitions = [
    'not started -> not started' => [EffectState::NotStarted, EffectState::NotStarted],
    'not started -> possibly applied' => [EffectState::NotStarted, EffectState::PossiblyApplied],
    'possibly applied -> possibly applied' => [EffectState::PossiblyApplied, EffectState::PossiblyApplied],
    'possibly applied -> not applied' => [EffectState::PossiblyApplied, EffectState::NotApplied],
    'possibly applied -> applied' => [EffectState::PossiblyApplied, EffectState::Applied],
    'not applied -> not applied' => [EffectState::NotApplied, EffectState::NotApplied],
    'applied -> applied' => [EffectState::Applied, EffectState::Applied],
];

dataset('allowed effect transitions', $allowedEffectTransitions);

$allowedEffectPairs = array_map(
    static fn (array $transition): string => "{$transition[0]->value}:{$transition[1]->value}",
    $allowedEffectTransitions,
);
$disallowedEffectTransitions = [];

foreach (EffectState::cases() as $fromEffectState) {
    foreach (EffectState::cases() as $toEffectState) {
        if (in_array("{$fromEffectState->value}:{$toEffectState->value}", $allowedEffectPairs, true)) {
            continue;
        }

        $disallowedEffectTransitions["{$fromEffectState->value} -> {$toEffectState->value}"] = [$fromEffectState, $toEffectState];
    }
}

dataset('disallowed effect transitions', $disallowedEffectTransitions);

$nonReadOnlyEffectTransitions = [];

foreach (EffectState::cases() as $fromEffectState) {
    foreach (EffectState::cases() as $toEffectState) {
        if ($fromEffectState === EffectState::NotStarted && $toEffectState === EffectState::NotStarted) {
            continue;
        }

        $nonReadOnlyEffectTransitions["{$fromEffectState->value} -> {$toEffectState->value}"] = [$fromEffectState, $toEffectState];
    }
}

dataset('non read-only effect transitions', $nonReadOnlyEffectTransitions);

it('starts every operation in pending with no remote effect', function (): void {
    $transition = (new OperationStateMachine)->initial();

    expect($transition->fromStatus)->toBeNull()
        ->and($transition->fromDisposition)->toBeNull()
        ->and($transition->fromEffectState)->toBeNull()
        ->and($transition->toStatus)->toBe(OperationStatus::Pending)
        ->and($transition->toDisposition)->toBe(OperationDisposition::InProgress)
        ->and($transition->toEffectState)->toBe(EffectState::NotStarted);
});

it('accepts every edge in the exact operation status graph', function (
    OperationStatus $fromStatus,
    OperationStatus $toStatus,
): void {
    $transition = (new OperationStateMachine)->transition(
        fromStatus: $fromStatus,
        fromEffectState: EffectState::NotStarted,
        toStatus: $toStatus,
        toEffectState: EffectState::NotStarted,
        maximumRemoteWrites: 0,
    );

    expect($transition->fromStatus)->toBe($fromStatus)
        ->and($transition->fromDisposition)->toBe($fromStatus->disposition())
        ->and($transition->toStatus)->toBe($toStatus)
        ->and($transition->toDisposition)->toBe($toStatus->disposition());
})->with('allowed operation status transitions');

it('rejects every edge outside the exact operation status graph', function (
    OperationStatus $fromStatus,
    OperationStatus $toStatus,
): void {
    expect(fn () => (new OperationStateMachine)->transition(
        fromStatus: $fromStatus,
        fromEffectState: EffectState::NotStarted,
        toStatus: $toStatus,
        toEffectState: EffectState::NotStarted,
        maximumRemoteWrites: 0,
    ))->toThrow(InvalidStateTransition::class);
})->with('disallowed operation status transitions');

it('requires retry wait to return through pending before processing', function (): void {
    $stateMachine = new OperationStateMachine;

    $pending = $stateMachine->transition(
        OperationStatus::RetryWait,
        EffectState::NotStarted,
        OperationStatus::Pending,
        EffectState::NotStarted,
        1,
    );
    $processing = $stateMachine->transition(
        $pending->toStatus,
        $pending->toEffectState,
        OperationStatus::Processing,
        EffectState::NotStarted,
        1,
    );

    expect($processing->toStatus)->toBe(OperationStatus::Processing)
        ->and(fn () => $stateMachine->transition(
            OperationStatus::RetryWait,
            EffectState::NotStarted,
            OperationStatus::Processing,
            EffectState::NotStarted,
            1,
        ))->toThrow(InvalidStateTransition::class);
});

it('allows only monotonic effect transitions', function (
    EffectState $fromEffectState,
    EffectState $toEffectState,
): void {
    $transition = (new OperationStateMachine)->transition(
        OperationStatus::Processing,
        $fromEffectState,
        OperationStatus::Processing,
        $toEffectState,
        1,
    );

    expect($transition->fromEffectState)->toBe($fromEffectState)
        ->and($transition->toEffectState)->toBe($toEffectState);
})->with('allowed effect transitions');

it('rejects every non-monotonic effect transition', function (
    EffectState $fromEffectState,
    EffectState $toEffectState,
): void {
    expect(fn () => (new OperationStateMachine)->transition(
        OperationStatus::Processing,
        $fromEffectState,
        OperationStatus::Processing,
        $toEffectState,
        1,
    ))->toThrow(InvalidStateTransition::class);
})->with('disallowed effect transitions');

it('does not allow a single-effect operation to succeed without crossing possibly applied', function (): void {
    expect(fn () => (new OperationStateMachine)->transition(
        OperationStatus::Processing,
        EffectState::NotStarted,
        OperationStatus::Succeeded,
        EffectState::Applied,
        1,
    ))->toThrow(InvalidStateTransition::class);
});

it('never allows possibly applied to become terminal', function (OperationStatus $terminalStatus): void {
    expect(fn () => (new OperationStateMachine)->transition(
        OperationStatus::ManualReview,
        EffectState::PossiblyApplied,
        $terminalStatus,
        EffectState::PossiblyApplied,
        1,
    ))->toThrow(InvalidStateTransition::class);
})->with([
    OperationStatus::Succeeded,
    OperationStatus::Failed,
    OperationStatus::Cancelled,
]);

it('keeps read-only operations in not started', function (
    EffectState $fromEffectState,
    EffectState $toEffectState,
): void {
    expect(fn () => (new OperationStateMachine)->transition(
        OperationStatus::Processing,
        $fromEffectState,
        OperationStatus::Processing,
        $toEffectState,
        0,
    ))->toThrow(InvalidStateTransition::class);
})->with('non read-only effect transitions');

it('never mutates a terminal source state', function (OperationStatus $terminalStatus): void {
    foreach (OperationStatus::cases() as $toStatus) {
        expect(fn () => (new OperationStateMachine)->transition(
            $terminalStatus,
            EffectState::NotStarted,
            $toStatus,
            EffectState::NotStarted,
            0,
        ))->toThrow(InvalidStateTransition::class);
    }
})->with([
    OperationStatus::Succeeded,
    OperationStatus::Failed,
    OperationStatus::Cancelled,
]);

it('allows cancellation only before a remote effect starts', function (OperationStatus $fromStatus): void {
    $stateMachine = new OperationStateMachine;
    $transition = $stateMachine->transition(
        $fromStatus,
        EffectState::NotStarted,
        OperationStatus::Cancelled,
        EffectState::NotStarted,
        1,
    );

    expect($transition->toStatus)->toBe(OperationStatus::Cancelled)
        ->and($transition->toEffectState)->toBe(EffectState::NotStarted);

    foreach (EffectState::cases() as $fromEffectState) {
        foreach (EffectState::cases() as $toEffectState) {
            if ($fromEffectState === EffectState::NotStarted && $toEffectState === EffectState::NotStarted) {
                continue;
            }

            expect(fn () => $stateMachine->transition(
                $fromStatus,
                $fromEffectState,
                OperationStatus::Cancelled,
                $toEffectState,
                1,
            ))->toThrow(InvalidStateTransition::class);
        }
    }
})->with([
    OperationStatus::Pending,
    OperationStatus::RetryWait,
    OperationStatus::ManualReview,
]);

it('rejects unsupported remote-write cardinalities', function (int $maximumRemoteWrites): void {
    expect(fn () => (new OperationStateMachine)->transition(
        OperationStatus::Pending,
        EffectState::NotStarted,
        OperationStatus::Processing,
        EffectState::NotStarted,
        $maximumRemoteWrites,
    ))->toThrow(InvalidStateTransition::class);
})->with([-1, 2, PHP_INT_MAX]);
