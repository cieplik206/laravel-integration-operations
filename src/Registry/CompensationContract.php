<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use InvalidArgumentException;

/** @api */
final readonly class CompensationContract
{
    private const int MaximumOutcomes = 8;

    /** @var list<TerminalOutcomePair> */
    public array $allowedTerminalOutcomes;

    /** @param list<TerminalOutcomePair> $allowedTerminalOutcomes */
    public function __construct(
        public OperationType $parentType,
        public string $slot,
        public OperationType $childType,
        array $allowedTerminalOutcomes,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $slot) !== 1) {
            throw new InvalidArgumentException('Compensation slot is invalid.');
        }

        $outcomes = ImmutableValueSanitizer::objectList(
            $allowedTerminalOutcomes,
            TerminalOutcomePair::class,
            'Compensation terminal outcomes',
        );

        if ($outcomes === [] || count($outcomes) > self::MaximumOutcomes) {
            throw new InvalidArgumentException('Compensation terminal outcomes must be bounded and non-empty.');
        }

        $keys = [];
        $hasSucceededApplied = false;

        foreach ($outcomes as $outcome) {
            $allowed = $outcome->effectState === EffectState::Applied
                && $outcome->resultAvailability === ResultAvailability::Available
                && in_array($outcome->status, [OperationStatus::Succeeded, OperationStatus::Failed], true);

            if (! $allowed || isset($keys[$outcome->key()])) {
                throw new InvalidArgumentException('Compensation terminal outcome is unsafe.');
            }

            $keys[$outcome->key()] = true;
            $hasSucceededApplied = $hasSucceededApplied
                || $outcome->status === OperationStatus::Succeeded;
        }

        if (! $hasSucceededApplied) {
            throw new InvalidArgumentException('Compensation requires an applied succeeded terminal outcome.');
        }

        usort($outcomes, static fn (TerminalOutcomePair $left, TerminalOutcomePair $right): int => $left->key() <=> $right->key());
        $this->allowedTerminalOutcomes = $outcomes;
    }
}
