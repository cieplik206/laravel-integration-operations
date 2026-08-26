<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\Enums\PollPurpose;
use DateTimeImmutable;

/** @api */
interface PollingContext extends OperationView
{
    public function pollPurpose(): PollPurpose;

    public function pollAttemptNumber(): int;

    public function pollStartedAt(): DateTimeImmutable;

    public function pollDeadlineAt(): DateTimeImmutable;
}
