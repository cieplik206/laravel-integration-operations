<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class TerminalOutcomePair
{
    /** @var list<TerminalProofKind> */
    public array $proofKinds;

    /** @param list<TerminalProofKind> $proofKinds */
    public function __construct(
        public OperationStatus $status,
        public EffectState $effectState,
        public ResultAvailability $resultAvailability,
        array $proofKinds,
    ) {
        $proofKinds = ImmutableValueSanitizer::enumList(
            $proofKinds,
            TerminalProofKind::class,
            'Terminal outcome proof kinds',
        );
        usort($proofKinds, static fn (TerminalProofKind $left, TerminalProofKind $right): int => $left->value <=> $right->value);
        $this->proofKinds = $proofKinds;
        $proofKindValues = array_map(
            static fn (TerminalProofKind $proofKind): string => $proofKind->value,
            $this->proofKinds,
        );

        if ($this->proofKinds === []
            || count(array_unique($proofKindValues, SORT_STRING)) !== count($this->proofKinds)
            || ! $this->proofKindsAreGloballyLegal()) {
            throw new InvalidArgumentException('Terminal outcome pair is invalid.');
        }
    }

    public function key(): string
    {
        return "{$this->status->value}|{$this->effectState->value}|{$this->resultAvailability->value}";
    }

    /** @return list<TerminalProofKind> */
    private function globallyAllowedProofKinds(): array
    {
        return match ($this->status) {
            OperationStatus::Succeeded => match ([$this->effectState, $this->resultAvailability]) {
                [EffectState::NotStarted, ResultAvailability::Available] => [
                    TerminalProofKind::Execute,
                    TerminalProofKind::Poll,
                    TerminalProofKind::SealedProviderEvidence,
                ],
                [EffectState::Applied, ResultAvailability::Available] => [
                    TerminalProofKind::Execute,
                    TerminalProofKind::Poll,
                    TerminalProofKind::Reconcile,
                    TerminalProofKind::SealedProviderEvidence,
                ],
                default => [],
            },
            OperationStatus::Failed => match ([$this->effectState, $this->resultAvailability]) {
                [EffectState::NotStarted, ResultAvailability::NotApplicable] => [
                    TerminalProofKind::Operator,
                    TerminalProofKind::PreEffect,
                ],
                [EffectState::NotStarted, ResultAvailability::Available] => [
                    TerminalProofKind::Poll,
                    TerminalProofKind::Reconcile,
                    TerminalProofKind::SealedProviderEvidence,
                ],
                [EffectState::NotApplied, ResultAvailability::NotApplicable] => [TerminalProofKind::Reconcile],
                [EffectState::Applied, ResultAvailability::Available] => [
                    TerminalProofKind::Poll,
                    TerminalProofKind::Reconcile,
                    TerminalProofKind::SealedProviderEvidence,
                ],
                default => [],
            },
            OperationStatus::Cancelled => match ([$this->effectState, $this->resultAvailability]) {
                [EffectState::NotStarted, ResultAvailability::NotApplicable] => [TerminalProofKind::Operator],
                default => [],
            },
            default => [],
        };
    }

    private function proofKindsAreGloballyLegal(): bool
    {
        $allowed = $this->globallyAllowedProofKinds();

        if ($allowed === []) {
            return false;
        }

        foreach ($this->proofKinds as $proofKind) {
            if (! in_array($proofKind, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
