<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;

/** @api */
interface ObservationProjectionPlanner
{
    public function plan(ObservationProjectionInput $input): ObservationProjectionPlan;
}
