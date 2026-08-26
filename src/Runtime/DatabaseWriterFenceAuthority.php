<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\Enums\OwnerMode;
use Cieplik206\IntegrationOperations\Exceptions\OperationPersistenceFailed;
use Cieplik206\IntegrationOperations\Exceptions\RuntimeTransactionActive;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceCutoverBlocked;
use Cieplik206\IntegrationOperations\Exceptions\WriterFenceUnavailable;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use stdClass;
use Throwable;

/** @internal */
final readonly class DatabaseWriterFenceAuthority
{
    public function __construct(
        private KernelDatabase $database,
        private HmacSha256 $hmac,
        private UlidFactory $ulids,
    ) {}

    /**
     * Locks or initializes the DB authority inside the caller's acceptance transaction.
     *
     * @throws WriterFenceUnavailable
     */
    public function lockForAcceptance(Connection $connection, PreparedAcceptance $prepared): DatabaseWriterFenceSnapshot
    {
        $scope = $prepared->command->scope;
        $operationType = $prepared->command->operationType;
        $configured = $prepared->writerFence;
        $authority = $this->lockCurrent($connection, $scope, $operationType);

        if ($authority === null) {
            $this->lockBootstrapKey($connection, $scope, $operationType);
            $authority = $this->lockCurrent($connection, $scope, $operationType);
        }

        if ($authority === null) {
            if ($connection->table('integration_operations')
                ->where('provider', $scope->provider->value)
                ->where('connection_key', $scope->connection->value)
                ->where('operation_type', $operationType->value)
                ->lockForUpdate()
                ->exists()) {
                throw new WriterFenceUnavailable;
            }

            $this->insertAuthority($connection, $scope, $operationType, $configured);
            $this->insertMissingAliases(
                $connection,
                $scope,
                $operationType,
                $configured->generation,
                $prepared->cohortDigests,
            );

            return $this->snapshotFromPrepared(
                $configured,
                $prepared->cohortDigests,
                $prepared->activeCohortDigest,
            );
        }

        if ($authority->generation !== $configured->generation
            || $authority->ownerMode !== $configured->ownerMode
            || $authority->cohortBound !== ($configured->cohort() !== null)) {
            throw new WriterFenceUnavailable;
        }

        $this->assertCohortMatchesAndBackfill(
            $connection,
            $authority,
            $prepared->cohortDigests,
        );

        return $this->snapshotFromPrepared(
            $configured,
            $prepared->cohortDigests,
            $prepared->activeCohortDigest,
        );
    }

    public function bootstrap(
        IntegrationScope $scope,
        OperationType $operationType,
        WriterFence $fence,
    ): void {
        $this->assertRuntimeTransactionIsOutermost();
        $baseline = $this->database->transactionLevels();
        $digests = $this->prepareCohortDigests($fence, $baseline);

        try {
            $connection = $this->database->connection();
            $connection->transaction(function () use ($connection, $scope, $operationType, $fence, $digests): void {
                $this->lockBootstrapKey($connection, $scope, $operationType);

                if ($this->lockCurrent($connection, $scope, $operationType) !== null) {
                    throw new WriterFenceCutoverBlocked;
                }

                $operations = $connection->table('integration_operations')
                    ->where('provider', $scope->provider->value)
                    ->where('connection_key', $scope->connection->value)
                    ->where('operation_type', $operationType->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get([
                        'writer_generation',
                        'owner_mode_at_accept',
                        'cohort_key_hmac',
                        'owner_hmac_key_version',
                        'completed_at',
                        'lease_owner',
                        'active_attempt_id',
                        'request_started_at',
                        'effect_state',
                    ]);

                foreach ($operations as $operation) {
                    if (! $this->operationCanBootstrap($operation, $fence, $digests)) {
                        throw new WriterFenceCutoverBlocked;
                    }
                }

                $this->insertAuthority($connection, $scope, $operationType, $fence);
                $this->insertMissingAliases(
                    $connection,
                    $scope,
                    $operationType,
                    $fence->generation,
                    $digests,
                );
            }, 3);
            $this->assertExactTransactionBaseline($baseline);
        } catch (WriterFenceCutoverBlocked) {
            $levelsWereExact = $this->transactionLevelsMatch($baseline);
            $this->restoreTransactionBaseline($baseline);

            if ($levelsWereExact) {
                throw new WriterFenceCutoverBlocked;
            }

            $this->reportPersistenceFailure($baseline);

            throw new OperationPersistenceFailed;
        } catch (Throwable) {
            $this->reportPersistenceFailure($baseline);

            throw new OperationPersistenceFailed;
        }
    }

    public function lockCurrent(
        Connection $connection,
        IntegrationScope $scope,
        OperationType $operationType,
    ): ?DatabaseWriterFenceAuthorityRecord {
        $row = $connection->table('integration_operation_writer_fences')
            ->where('provider', $scope->provider->value)
            ->where('connection_key', $scope->connection->value)
            ->where('operation_type', $operationType->value)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return null;
        }

        if (! is_int($row->generation ?? null)
            || ! is_string($row->owner_mode ?? null)
            || ! is_bool($row->cohort_bound ?? null)
            || ! is_int($row->epoch ?? null)) {
            throw new WriterFenceUnavailable;
        }

        $ownerMode = OwnerMode::tryFrom($row->owner_mode);

        if ($ownerMode === null || $row->generation < 1 || $row->epoch < 1) {
            throw new WriterFenceUnavailable;
        }

        return new DatabaseWriterFenceAuthorityRecord(
            $scope,
            $operationType,
            $row->generation,
            $ownerMode,
            $row->cohort_bound,
            $row->epoch,
        );
    }

    public function lockCurrentForClaim(
        Connection $connection,
        IntegrationScope $scope,
        OperationType $operationType,
    ): ?DatabaseWriterFenceAuthorityRecord {
        $authority = $this->lockCurrent($connection, $scope, $operationType);

        if ($authority !== null) {
            return $authority;
        }

        $this->lockBootstrapKey($connection, $scope, $operationType);

        return $this->lockCurrent($connection, $scope, $operationType);
    }

    public function operationMatches(
        DatabaseWriterFenceAuthorityRecord $authority,
        DatabaseWriterFenceAliasSet $aliases,
        stdClass $operation,
    ): bool {
        if (! is_string($operation->provider ?? null)
            || ! is_string($operation->connection_key ?? null)
            || ! is_string($operation->operation_type ?? null)
            || ! is_int($operation->writer_generation ?? null)
            || ! is_string($operation->owner_mode_at_accept ?? null)
            || ! hash_equals($authority->scope->provider->value, $operation->provider)
            || ! hash_equals($authority->scope->connection->value, $operation->connection_key)
            || ! hash_equals($authority->operationType->value, $operation->operation_type)
            || $authority->generation !== $operation->writer_generation
            || ! hash_equals($authority->ownerMode->value, $operation->owner_mode_at_accept)) {
            return false;
        }

        $digest = $operation->cohort_key_hmac ?? null;
        $keyVersion = $operation->owner_hmac_key_version ?? null;

        if (! $authority->cohortBound) {
            return $digest === null && $keyVersion === null;
        }

        if (! is_string($digest) || ! is_int($keyVersion)) {
            return false;
        }

        return $aliases->matches($keyVersion, $digest);
    }

    public function configurationMatches(
        DatabaseWriterFenceAuthorityRecord $authority,
        DatabaseWriterFenceAliasSet $aliases,
        DatabaseWriterFenceSnapshot $configured,
    ): bool {
        if (! $configured->available
            || $configured->generation === null
            || $configured->ownerMode === null
            || $configured->generation !== $authority->generation
            || $configured->ownerMode !== $authority->ownerMode) {
            return false;
        }

        if (! $authority->cohortBound) {
            return $configured->cohortDigest === null
                && $configured->cohortKeyVersion === null
                && $aliases->equals($configured->trustedCohortAliases);
        }

        if ($configured->cohortDigest === null || $configured->cohortKeyVersion === null) {
            return false;
        }

        return $aliases->equals($configured->trustedCohortAliases)
            && $aliases->matches($configured->cohortKeyVersion, $configured->cohortDigest);
    }

    public function lockAliases(
        Connection $connection,
        DatabaseWriterFenceAuthorityRecord $authority,
    ): DatabaseWriterFenceAliasSet {
        return $this->activeAliasSet($authority, $this->lockAliasRows($connection, $authority));
    }

    public function lockAliasesAndBackfillForBoundary(
        Connection $connection,
        DatabaseWriterFenceAuthorityRecord $authority,
        DatabaseWriterFenceSnapshot $configured,
    ): DatabaseWriterFenceAliasSet {
        $rows = $this->lockAliasRows($connection, $authority);
        $aliases = $this->activeAliasSet($authority, $rows);

        if (! $configured->available
            || $configured->generation !== $authority->generation
            || $configured->ownerMode !== $authority->ownerMode
            || ! $authority->cohortBound
            || ! $aliases->isSubsetOf($configured->trustedCohortAliases)) {
            return $aliases;
        }

        foreach ($rows as $row) {
            if (! is_int($row->key_version ?? null)) {
                throw new WriterFenceUnavailable;
            }

            if ($row->retired_at !== null
                && $configured->trustedCohortAliases->containsKeyVersion($row->key_version)) {
                return $aliases;
            }
        }

        $missing = $configured->trustedCohortAliases->missingFrom($aliases);

        if ($missing === []) {
            return $aliases;
        }

        $this->insertMissingAliases(
            $connection,
            $authority->scope,
            $authority->operationType,
            $authority->generation,
            $missing,
        );

        return $this->activeAliasSet($authority, $this->lockAliasRows($connection, $authority));
    }

    public function cutover(
        IntegrationScope $scope,
        OperationType $operationType,
        int $expectedGeneration,
        WriterFence $next,
    ): void {
        if ($expectedGeneration < 1 || $next->generation !== $expectedGeneration + 1) {
            throw new InvalidArgumentException('Writer-fence cutover generation must advance exactly once.');
        }

        $this->assertRuntimeTransactionIsOutermost();
        $baseline = $this->database->transactionLevels();
        $cohort = $next->cohort();
        $digests = $this->prepareCohortDigests($next, $baseline);

        try {
            $connection = $this->database->connection();
            $connection->transaction(function () use (
                $connection,
                $scope,
                $operationType,
                $expectedGeneration,
                $next,
                $digests,
                $cohort,
            ): void {
                $current = $this->lockCurrent($connection, $scope, $operationType)
                    ?? throw new WriterFenceCutoverBlocked;
                $this->lockAliases($connection, $current);

                if ($current->generation !== $expectedGeneration
                    || $this->hasOldInFlightWork($connection, $current)) {
                    throw new WriterFenceCutoverBlocked;
                }

                $this->insertMissingAliases(
                    $connection,
                    $scope,
                    $operationType,
                    $next->generation,
                    $digests,
                );
                $now = $connection->selectOne('SELECT clock_timestamp() AS observed_at');

                if (! $now instanceof stdClass || ! is_string($now->observed_at ?? null)) {
                    throw new WriterFenceCutoverBlocked;
                }

                $updated = $connection->table('integration_operation_writer_fences')
                    ->where('provider', $scope->provider->value)
                    ->where('connection_key', $scope->connection->value)
                    ->where('operation_type', $operationType->value)
                    ->where('generation', $expectedGeneration)
                    ->where('epoch', $current->epoch)
                    ->update([
                        'generation' => $next->generation,
                        'owner_mode' => $next->ownerMode->value,
                        'cohort_bound' => $cohort !== null,
                        'epoch' => $current->epoch + 1,
                        'updated_at' => $now->observed_at,
                    ]);

                if ($updated !== 1) {
                    throw new WriterFenceCutoverBlocked;
                }
            }, 3);
            $this->assertExactTransactionBaseline($baseline);
        } catch (WriterFenceCutoverBlocked) {
            $levelsWereExact = $this->transactionLevelsMatch($baseline);
            $this->restoreTransactionBaseline($baseline);

            if ($levelsWereExact) {
                throw new WriterFenceCutoverBlocked;
            }

            $this->reportPersistenceFailure($baseline);

            throw new OperationPersistenceFailed;
        } catch (Throwable) {
            $this->reportPersistenceFailure($baseline);

            throw new OperationPersistenceFailed;
        }
    }

    private function insertAuthority(
        Connection $connection,
        IntegrationScope $scope,
        OperationType $operationType,
        WriterFence $fence,
    ): void {
        $now = $connection->raw('clock_timestamp()');

        $connection->table('integration_operation_writer_fences')->insert([
            'provider' => $scope->provider->value,
            'connection_key' => $scope->connection->value,
            'operation_type' => $operationType->value,
            'generation' => $fence->generation,
            'owner_mode' => $fence->ownerMode->value,
            'cohort_bound' => $fence->cohort() !== null,
            'epoch' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param list<VersionedHmacDigest> $digests */
    private function assertCohortMatchesAndBackfill(
        Connection $connection,
        DatabaseWriterFenceAuthorityRecord $authority,
        array $digests,
    ): void {
        $aliases = $connection->table('integration_operation_writer_fence_aliases')
            ->where('provider', $authority->scope->provider->value)
            ->where('connection_key', $authority->scope->connection->value)
            ->where('operation_type', $authority->operationType->value)
            ->where('generation', $authority->generation)
            ->orderBy('key_version')
            ->lockForUpdate()
            ->get(['key_version', 'digest', 'retired_at']);

        if (! $authority->cohortBound) {
            if ($digests !== [] || $aliases->isNotEmpty()) {
                throw new WriterFenceUnavailable;
            }

            return;
        }

        if ($digests === []) {
            throw new WriterFenceUnavailable;
        }

        $expected = [];
        foreach ($digests as $digest) {
            $expected[$digest->keyVersion] = $digest;
        }

        $matched = false;
        $present = [];
        foreach ($aliases as $alias) {
            if (! is_int($alias->key_version ?? null)
                || ! is_string($alias->digest ?? null)) {
                throw new WriterFenceUnavailable;
            }

            $candidate = $expected[$alias->key_version] ?? null;

            if ($candidate === null) {
                if ($alias->retired_at === null) {
                    throw new WriterFenceUnavailable;
                }

                continue;
            }

            if ($alias->retired_at !== null || ! hash_equals($candidate->hex, $alias->digest)) {
                throw new WriterFenceUnavailable;
            }

            $matched = true;
            $present[$alias->key_version] = true;
        }

        if (! $matched) {
            throw new WriterFenceUnavailable;
        }

        $this->insertMissingAliases(
            $connection,
            $authority->scope,
            $authority->operationType,
            $authority->generation,
            array_values(array_filter(
                $digests,
                fn (VersionedHmacDigest $digest): bool => ! isset($present[$digest->keyVersion]),
            )),
        );
    }

    /** @param list<VersionedHmacDigest> $digests */
    private function insertMissingAliases(
        Connection $connection,
        IntegrationScope $scope,
        OperationType $operationType,
        int $generation,
        array $digests,
    ): void {
        foreach ($digests as $digest) {
            $connection->table('integration_operation_writer_fence_aliases')->insert([
                'id' => $this->ulids->generate()->value,
                'provider' => $scope->provider->value,
                'connection_key' => $scope->connection->value,
                'operation_type' => $operationType->value,
                'generation' => $generation,
                'key_version' => $digest->keyVersion,
                'digest' => $digest->hex,
                'created_at' => $connection->raw('clock_timestamp()'),
            ]);
        }
    }

    /** @param list<VersionedHmacDigest> $digests */
    private function snapshotFromPrepared(
        WriterFence $fence,
        array $digests,
        ?VersionedHmacDigest $activeDigest,
    ): DatabaseWriterFenceSnapshot {
        $trustedAliases = DatabaseWriterFenceAliasSet::fromDigests($digests);

        if ($fence->cohort() === null) {
            return DatabaseWriterFenceSnapshot::available(
                $fence->generation,
                $fence->ownerMode,
                null,
                null,
                $trustedAliases,
            );
        }

        if ($activeDigest !== null) {
            return DatabaseWriterFenceSnapshot::available(
                $fence->generation,
                $fence->ownerMode,
                $activeDigest->hex,
                $activeDigest->keyVersion,
                $trustedAliases,
            );
        }

        throw new WriterFenceUnavailable;
    }

    private function hasOldInFlightWork(
        Connection $connection,
        DatabaseWriterFenceAuthorityRecord $current,
    ): bool {
        return $connection->table('integration_operations')
            ->where('provider', $current->scope->provider->value)
            ->where('connection_key', $current->scope->connection->value)
            ->where('operation_type', $current->operationType->value)
            ->where('writer_generation', $current->generation)
            ->whereNull('completed_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    /** @param list<VersionedHmacDigest> $digests */
    private function operationCanBootstrap(stdClass $operation, WriterFence $fence, array $digests): bool
    {
        if (! is_int($operation->writer_generation ?? null)
            || ! is_string($operation->owner_mode_at_accept ?? null)
            || ! is_string($operation->effect_state ?? null)
            || $operation->writer_generation !== $fence->generation
            || ! hash_equals($fence->ownerMode->value, $operation->owner_mode_at_accept)
            || ($operation->completed_at === null && (
                $operation->lease_owner !== null
                || $operation->active_attempt_id !== null
                || $operation->request_started_at !== null
                || $operation->effect_state !== 'not_started'
            ))) {
            return false;
        }

        $cohort = $fence->cohort();

        if ($cohort === null) {
            return $operation->cohort_key_hmac === null
                && $operation->owner_hmac_key_version === null;
        }

        if (! is_string($operation->cohort_key_hmac ?? null)
            || ! is_int($operation->owner_hmac_key_version ?? null)) {
            return false;
        }

        foreach ($digests as $digest) {
            if ($digest->keyVersion === $operation->owner_hmac_key_version
                && hash_equals($digest->hex, $operation->cohort_key_hmac)) {
                return true;
            }
        }

        return false;
    }

    private function lockBootstrapKey(
        Connection $connection,
        IntegrationScope $scope,
        OperationType $operationType,
    ): void {
        $connection->selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            [implode('|', [
                'integration-operation-writer-fence',
                $scope->provider->value,
                $scope->connection->value,
                $operationType->value,
            ])],
        );
    }

    /** @return Collection<int, stdClass> */
    private function lockAliasRows(
        Connection $connection,
        DatabaseWriterFenceAuthorityRecord $authority,
    ): Collection {
        return $connection->table('integration_operation_writer_fence_aliases')
            ->where('provider', $authority->scope->provider->value)
            ->where('connection_key', $authority->scope->connection->value)
            ->where('operation_type', $authority->operationType->value)
            ->where('generation', $authority->generation)
            ->orderBy('key_version')
            ->lockForUpdate()
            ->get(['key_version', 'digest', 'retired_at']);
    }

    /** @param Collection<int, stdClass> $rows */
    private function activeAliasSet(
        DatabaseWriterFenceAuthorityRecord $authority,
        Collection $rows,
    ): DatabaseWriterFenceAliasSet {
        $active = [];

        foreach ($rows as $row) {
            if (! is_int($row->key_version ?? null)
                || ! is_string($row->digest ?? null)) {
                throw new WriterFenceUnavailable;
            }

            if ($row->retired_at === null) {
                $active[$row->key_version] = $row->digest;
            }
        }

        $aliases = new DatabaseWriterFenceAliasSet($active);

        if ($authority->cohortBound === $aliases->isEmpty()) {
            throw new WriterFenceUnavailable;
        }

        return $aliases;
    }

    private function assertRuntimeTransactionIsOutermost(): void
    {
        $this->database->assertNoForeignTransaction();

        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new RuntimeTransactionActive;
        }
    }

    /**
     * @param  array<string, int>  $baseline
     * @return list<VersionedHmacDigest>
     */
    private function prepareCohortDigests(WriterFence $fence, array $baseline): array
    {
        try {
            $cohort = $fence->cohort();
            $digests = $cohort === null
                ? []
                : $this->hmac->readableDigests(LookupHmacDomain::Cohort, $cohort);
        } catch (Throwable) {
            $this->reportPersistenceFailure($baseline);

            throw new OperationPersistenceFailed;
        }

        if (! $this->transactionLevelsMatch($baseline)) {
            $this->reportPersistenceFailure($baseline);

            throw new OperationPersistenceFailed;
        }

        return $digests;
    }

    /** @param array<string, int> $baseline */
    private function assertExactTransactionBaseline(array $baseline): void
    {
        if ($this->transactionLevelsMatch($baseline)) {
            return;
        }

        $this->reportPersistenceFailure($baseline);

        throw new OperationPersistenceFailed;
    }

    /** @param array<string, int> $baseline */
    private function restoreTransactionBaseline(array $baseline): void
    {
        try {
            $this->database->restoreTransactionLevels($baseline);
        } catch (Throwable) {
            throw new OperationPersistenceFailed;
        }

        if (! $this->transactionLevelsMatch($baseline)) {
            throw new OperationPersistenceFailed;
        }
    }

    /** @param array<string, int> $baseline */
    private function transactionLevelsMatch(array $baseline): bool
    {
        $current = $this->database->transactionLevels();
        ksort($current);
        ksort($baseline);

        return $current === $baseline;
    }

    /** @param array<string, int> $baseline */
    private function reportPersistenceFailure(array $baseline): void
    {
        $this->restoreTransactionBaseline($baseline);

        try {
            report(new OperationPersistenceFailed);
        } catch (Throwable) {
        } finally {
            $this->restoreTransactionBaseline($baseline);
        }
    }
}
