<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum AuthoritativeReconciliationResult: string
{
    case FoundExact = 'found_exact';
    case AppliedInProgress = 'applied_in_progress';
    case AbsentConclusive = 'absent_conclusive';
    case Inconclusive = 'inconclusive';
    case AmbiguousMatches = 'ambiguous_matches';
    case ProviderRejected = 'provider_rejected';
}
