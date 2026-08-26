<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;

/** @api */
interface OutcomeProjectionPlanner
{
    public function plan(ProjectionInput $input): ProjectionPlan;
}
