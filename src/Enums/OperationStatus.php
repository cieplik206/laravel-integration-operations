<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum OperationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case RetryWait = 'retry_wait';
    case Uncertain = 'uncertain';
    case Reconciling = 'reconciling';
    case ManualReview = 'manual_review';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function disposition(): OperationDisposition
    {
        return match ($this) {
            self::Pending,
            self::Processing,
            self::RetryWait,
            self::Uncertain,
            self::Reconciling => OperationDisposition::InProgress,
            self::ManualReview => OperationDisposition::RequiresManualReview,
            self::Succeeded => OperationDisposition::Succeeded,
            self::Failed => OperationDisposition::Failed,
            self::Cancelled => OperationDisposition::Cancelled,
        };
    }
}
