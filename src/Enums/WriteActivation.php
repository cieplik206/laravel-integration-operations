<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/** @api */
enum WriteActivation: string
{
    case Disabled = 'disabled';
    case ImmediateExecute = 'immediate_execute';
    case PollSendRequired = 'poll_send_required';
}
