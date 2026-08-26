<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum SuccessEffectPolicy: string
{
    case MustBeAppliedByOperation = 'must_be_applied_by_operation';
    case MayBeObservedExternally = 'may_be_observed_externally';
    case ReadOnly = 'read_only';
}
