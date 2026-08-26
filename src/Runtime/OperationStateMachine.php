<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Exceptions\InvalidStateTransition;

/** @internal */
final class OperationStateMachine
{
    public function initial(): StateTransition
    {
        return new StateTransition(
            fromStatus: null,
            fromDisposition: null,
            fromEffectState: null,
            toStatus: OperationStatus::Pending,
            toDisposition: OperationStatus::Pending->disposition(),
            toEffectState: EffectState::NotStarted,
        );
    }

    public function transition(
        OperationStatus $fromStatus,
        EffectState $fromEffectState,
        OperationStatus $toStatus,
        EffectState $toEffectState,
        int $maximumRemoteWrites,
    ): StateTransition {
        if ($fromStatus->disposition()->isTerminal()) {
            throw new InvalidStateTransition;
        }

        if (! in_array($maximumRemoteWrites, [0, 1], true)) {
            throw new InvalidStateTransition;
        }

        if (! $this->isAllowedEdge($fromStatus, $toStatus)) {
            throw new InvalidStateTransition;
        }

        $this->assertEffectInvariant($fromStatus, $fromEffectState, $toStatus, $toEffectState, $maximumRemoteWrites);

        return new StateTransition(
            fromStatus: $fromStatus,
            fromDisposition: $fromStatus->disposition(),
            fromEffectState: $fromEffectState,
            toStatus: $toStatus,
            toDisposition: $toStatus->disposition(),
            toEffectState: $toEffectState,
        );
    }

    private function isAllowedEdge(OperationStatus $from, OperationStatus $to): bool
    {
        return match ($from) {
            OperationStatus::Pending => in_array($to, [
                OperationStatus::Processing,
                OperationStatus::ManualReview,
                OperationStatus::Cancelled,
            ], true),
            OperationStatus::Processing => in_array($to, [
                OperationStatus::Pending,
                OperationStatus::Processing,
                OperationStatus::RetryWait,
                OperationStatus::Uncertain,
                OperationStatus::ManualReview,
                OperationStatus::Succeeded,
                OperationStatus::Failed,
            ], true),
            OperationStatus::RetryWait => in_array($to, [
                OperationStatus::Pending,
                OperationStatus::ManualReview,
                OperationStatus::Cancelled,
            ], true),
            OperationStatus::Uncertain => in_array($to, [
                OperationStatus::Reconciling,
                OperationStatus::ManualReview,
            ], true),
            OperationStatus::Reconciling => in_array($to, [
                OperationStatus::Uncertain,
                OperationStatus::ManualReview,
                OperationStatus::Succeeded,
                OperationStatus::Failed,
            ], true),
            OperationStatus::ManualReview => in_array($to, [
                OperationStatus::Reconciling,
                OperationStatus::Succeeded,
                OperationStatus::Failed,
                OperationStatus::Cancelled,
            ], true),
            OperationStatus::Succeeded,
            OperationStatus::Failed,
            OperationStatus::Cancelled,
            OperationStatus::PollWait,
            OperationStatus::Polling => false,
        };
    }

    private function assertEffectInvariant(
        OperationStatus $fromStatus,
        EffectState $fromEffect,
        OperationStatus $toStatus,
        EffectState $toEffect,
        int $maximumRemoteWrites,
    ): void {
        if ($maximumRemoteWrites === 0 && ($fromEffect !== EffectState::NotStarted || $toEffect !== EffectState::NotStarted)) {
            throw new InvalidStateTransition;
        }

        if (in_array($toStatus, [OperationStatus::Pending, OperationStatus::RetryWait, OperationStatus::Cancelled], true)
            && $toEffect !== EffectState::NotStarted) {
            throw new InvalidStateTransition;
        }

        if ($toStatus === OperationStatus::Failed && ! in_array($toEffect, [EffectState::NotStarted, EffectState::NotApplied], true)) {
            throw new InvalidStateTransition;
        }

        if ($toStatus === OperationStatus::Succeeded) {
            $requiredEffect = $maximumRemoteWrites === 0 ? EffectState::NotStarted : EffectState::Applied;

            if ($toEffect !== $requiredEffect) {
                throw new InvalidStateTransition;
            }
        }

        if ($toEffect === EffectState::PossiblyApplied && $toStatus->disposition()->isTerminal()) {
            throw new InvalidStateTransition;
        }

        if ($fromEffect !== EffectState::NotStarted && $toEffect === EffectState::NotStarted) {
            throw new InvalidStateTransition;
        }

        if ($fromEffect === EffectState::NotStarted
            && ! in_array($toEffect, [EffectState::NotStarted, EffectState::PossiblyApplied], true)) {
            throw new InvalidStateTransition;
        }

        if ($fromEffect === EffectState::PossiblyApplied
            && ! in_array($toEffect, [EffectState::PossiblyApplied, EffectState::NotApplied, EffectState::Applied], true)) {
            throw new InvalidStateTransition;
        }

        if ($fromEffect === EffectState::NotApplied && $toEffect !== EffectState::NotApplied) {
            throw new InvalidStateTransition;
        }

        if ($fromEffect === EffectState::Applied && $toEffect !== EffectState::Applied) {
            throw new InvalidStateTransition;
        }

    }
}
