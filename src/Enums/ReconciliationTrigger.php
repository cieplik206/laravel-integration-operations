<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum ReconciliationTrigger: string
{
    case LostResponse = 'lost_response';
    case DuplicateEnvelope = 'duplicate_envelope';
    case OidConflict = 'oid_conflict';
    case Unknown = 'unknown';
}
