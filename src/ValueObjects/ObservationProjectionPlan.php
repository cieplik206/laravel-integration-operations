<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Registry\ProjectionContract;

/** @api */
final readonly class ObservationProjectionPlan
{
    /** @var list<ProjectionMutation> */
    public array $mutations;

    /** @param list<ProjectionMutation> $mutations */
    public function __construct(
        public int $schemaVersion,
        array $mutations,
    ) {
        $this->mutations = (new ProjectionPlan($schemaVersion, $mutations))->mutations;
    }

    public function isCompatibleWith(ProjectionContract $contract): bool
    {
        return (new ProjectionPlan($this->schemaVersion, $this->mutations))->isCompatibleWith($contract);
    }
}
