<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum ResultAvailability: string
{
    case NotReady = 'not_ready';
    case Available = 'available';
    case NotApplicable = 'not_applicable';
    case CodecUnavailable = 'codec_unavailable';
    case DecodeFailed = 'decode_failed';
}
