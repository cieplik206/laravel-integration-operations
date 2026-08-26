<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class ProjectionContract
{
    private const int MaximumTargets = 8;

    /** @var list<string> */
    public array $targetIds;

    /** @param list<string> $targetIds */
    public function __construct(
        public ServiceReference $planner,
        public int $schemaVersion,
        array $targetIds,
    ) {
        if ((! $planner->targets(OutcomeProjectionPlanner::class)
                && ! $planner->targets(ObservationProjectionPlanner::class))
            || $schemaVersion < 1
            || $schemaVersion > 65_535) {
            throw new InvalidArgumentException('Projection contract schema version is invalid.');
        }

        $targets = ImmutableValueSanitizer::stringList($targetIds, 'Projection target IDs');

        if (count($targets) > self::MaximumTargets
            || count(array_unique($targets, SORT_STRING)) !== count($targets)) {
            throw new InvalidArgumentException('Projection target IDs must be bounded and unique.');
        }

        foreach ($targets as $target) {
            if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $target) !== 1) {
                throw new InvalidArgumentException('Projection target ID is invalid.');
            }
        }

        sort($targets, SORT_STRING);
        $this->targetIds = $targets;
    }

    /** @param class-string $contract */
    public function targetsPlannerContract(string $contract): bool
    {
        return $this->planner->targets($contract);
    }
}
