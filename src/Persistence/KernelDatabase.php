<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Persistence;

use Cieplik206\IntegrationOperations\Exceptions\CrossConnectionTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

/** @internal */
final readonly class KernelDatabase
{
    public function __construct(
        private DatabaseManager $database,
        private Repository $config,
    ) {}

    public function connection(): Connection
    {
        return $this->database->connection($this->connectionName());
    }

    public function connectionName(): string
    {
        $configured = $this->config->get('integration-operations.database.connection');

        if ($configured === null || $configured === '') {
            return $this->database->getDefaultConnection();
        }

        if (! is_string($configured)) {
            throw new InvalidArgumentException('Integration operations database connection must be a string or null.');
        }

        return $configured;
    }

    public function assertNoForeignTransaction(): void
    {
        $kernelConnection = $this->connectionName();

        foreach ($this->database->getConnections() as $name => $connection) {
            if ($name === $kernelConnection) {
                continue;
            }

            if ($connection->transactionLevel() > 0) {
                throw new CrossConnectionTransaction;
            }
        }
    }

    /** @return array<string, int> */
    public function transactionLevels(): array
    {
        $levels = [];

        foreach ($this->database->getConnections() as $name => $connection) {
            $levels[(string) $name] = $connection->transactionLevel();
        }

        return $levels;
    }

    /** @param array<string, int> $baseline */
    public function restoreTransactionLevels(array $baseline): void
    {
        $cleanupFailed = false;

        foreach ($this->database->getConnections() as $name => $connection) {
            $targetLevel = $baseline[(string) $name] ?? 0;

            if ($connection->transactionLevel() < $targetLevel) {
                $cleanupFailed = true;

                continue;
            }

            if ($connection->transactionLevel() > $targetLevel) {
                try {
                    $connection->rollBack($targetLevel);
                } catch (Throwable) {
                    $cleanupFailed = true;

                    continue;
                }
            }

            if ($connection->transactionLevel() !== $targetLevel) {
                $cleanupFailed = true;
            }
        }

        if ($cleanupFailed) {
            throw new InvalidArgumentException('Incident notifier transaction cleanup failed.');
        }
    }
}
