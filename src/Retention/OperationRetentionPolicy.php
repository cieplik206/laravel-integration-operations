<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Retention;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/** @internal */
final readonly class OperationRetentionPolicy
{
    private const int MINIMUM_TOMBSTONE_DAYS = 1825;

    public function __construct(
        public int $rawPayloadDays,
        public int $attemptDiagnosticsDays,
        public int $terminalTombstoneDays,
        public int $batchSize,
    ) {
        if ($this->rawPayloadDays < 1) {
            throw new InvalidArgumentException('Raw payload retention must be at least one day.');
        }

        if ($this->attemptDiagnosticsDays < $this->rawPayloadDays) {
            throw new InvalidArgumentException('Attempt diagnostics cannot expire before raw payloads.');
        }

        if ($this->terminalTombstoneDays < self::MINIMUM_TOMBSTONE_DAYS) {
            throw new InvalidArgumentException('Terminal tombstones must be retained for at least five years.');
        }

        if ($this->batchSize < 1 || $this->batchSize > 5000) {
            throw new InvalidArgumentException('Retention batch size must be between 1 and 5000.');
        }
    }

    public function rawPayloadCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval(sprintf('P%dD', $this->rawPayloadDays)));
    }

    public function attemptDiagnosticsCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval(sprintf('P%dD', $this->attemptDiagnosticsDays)));
    }

    public function terminalTombstoneCutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval(sprintf('P%dD', $this->terminalTombstoneDays)));
    }
}
