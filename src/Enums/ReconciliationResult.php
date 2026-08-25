<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum ReconciliationResult: string
{
    case FoundExact = 'found_exact';
    case AbsentConclusive = 'absent_conclusive';
    case Inconclusive = 'inconclusive';
    case AmbiguousMatches = 'ambiguous_matches';
}
