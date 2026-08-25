<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum OperationDisposition: string
{
    case InProgress = 'in_progress';
    case RequiresManualReview = 'requires_manual_review';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            self::InProgress, self::RequiresManualReview => false,
        };
    }
}
