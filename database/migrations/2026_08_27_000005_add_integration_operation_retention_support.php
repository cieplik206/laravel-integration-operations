<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_operations', function (Blueprint $table): void {
            $table->index(['status', 'completed_at', 'id'], 'io_operations_retention_idx');
        });
        Schema::table('integration_operation_payloads', function (Blueprint $table): void {
            $table->index(['payload_pruned_at', 'operation_id', 'id'], 'io_payloads_retention_idx');
        });
        Schema::table('integration_operation_attempts', function (Blueprint $table): void {
            $table->timestampTz('diagnostics_pruned_at', precision: 6)->nullable();
            $table->index(
                ['diagnostics_pruned_at', 'finished_at', 'operation_id', 'id'],
                'io_attempts_retention_idx',
            );
        });

        $this->replacePostgresAttemptGuard();
    }

    public function down(): void
    {
        $this->restorePostgresAttemptGuard();

        Schema::table('integration_operation_attempts', function (Blueprint $table): void {
            $table->dropIndex('io_attempts_retention_idx');
            $table->dropColumn('diagnostics_pruned_at');
        });
        Schema::table('integration_operation_payloads', function (Blueprint $table): void {
            $table->dropIndex('io_payloads_retention_idx');
        });
        Schema::table('integration_operations', function (Blueprint $table): void {
            $table->dropIndex('io_operations_retention_idx');
        });
    }

    public function getConnection(): ?string
    {
        $connection = config('integration-operations.database.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('Integration operations database connection must be a string or null.');
        }

        return $connection;
    }

    private function replacePostgresAttemptGuard(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION io_guard_attempt_finalize_once() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'integration operation attempts cannot be deleted' USING ERRCODE = '55000';
                END IF;

                IF OLD.finished_at IS NOT NULL
                    AND OLD.diagnostics_pruned_at IS NULL
                    AND NEW.diagnostics_pruned_at IS NOT NULL
                    AND current_setting('integration_operations.retention', true) = 'on'
                    AND NEW.transport IS NULL
                    AND NEW.request_method IS NULL
                    AND NEW.target_template IS NULL
                    AND NEW.request_fingerprint IS NULL
                    AND NEW.request_started_at IS NULL
                    AND NEW.response_received_at IS NULL
                    AND NEW.response_code IS NULL
                    AND NEW.provider_request_id IS NULL
                    AND NEW.error_category IS NULL
                    AND NEW.error_code IS NULL
                    AND NEW.safe_metadata IS NULL
                    AND (to_jsonb(NEW) - ARRAY[
                        'transport',
                        'request_method',
                        'target_template',
                        'request_fingerprint',
                        'request_started_at',
                        'response_received_at',
                        'response_code',
                        'provider_request_id',
                        'error_category',
                        'error_code',
                        'safe_metadata',
                        'diagnostics_pruned_at'
                    ]) IS NOT DISTINCT FROM (to_jsonb(OLD) - ARRAY[
                        'transport',
                        'request_method',
                        'target_template',
                        'request_fingerprint',
                        'request_started_at',
                        'response_received_at',
                        'response_code',
                        'provider_request_id',
                        'error_category',
                        'error_code',
                        'safe_metadata',
                        'diagnostics_pruned_at'
                    ]) THEN
                    RETURN NEW;
                END IF;

                IF OLD.finished_at IS NULL AND NEW.finished_at IS NULL THEN
                    IF OLD.mode IS DISTINCT FROM 'execute'
                        OR OLD.request_started_at IS NOT NULL
                        OR NEW.request_started_at IS NULL
                        OR NEW.response_received_at IS NOT NULL
                        OR (to_jsonb(NEW) - 'request_started_at') IS DISTINCT FROM (to_jsonb(OLD) - 'request_started_at')
                        OR NOT EXISTS (
                            SELECT 1
                            FROM integration_operations
                            WHERE id = NEW.operation_id
                                AND active_attempt_id = NEW.id
                                AND status = 'processing'
                                AND max_remote_writes = 1
                                AND effect_state = 'possibly_applied'
                                AND request_started_at = NEW.request_started_at
                                AND lease_owner = NEW.worker_identity
                                AND lease_token_sha256 = NEW.lease_token_sha256
                        ) THEN
                        RAISE EXCEPTION 'integration operation effect boundary marker is invalid' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.finished_at IS NOT NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NEW.attempt_no IS DISTINCT FROM OLD.attempt_no
                    OR NEW.mode IS DISTINCT FROM OLD.mode
                    OR NEW.effect_state_before IS DISTINCT FROM OLD.effect_state_before
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.worker_identity IS DISTINCT FROM OLD.worker_identity
                    OR NEW.lease_token_sha256 IS DISTINCT FROM OLD.lease_token_sha256
                    OR NEW.request_started_at IS DISTINCT FROM OLD.request_started_at
                    OR (OLD.response_received_at IS NOT NULL AND NEW.response_received_at IS DISTINCT FROM OLD.response_received_at)
                    OR NEW.finished_at IS NULL THEN
                    RAISE EXCEPTION 'integration operation attempt may be finalized exactly once' USING ERRCODE = '55000';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }

    private function restorePostgresAttemptGuard(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION io_guard_attempt_finalize_once() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'integration operation attempts cannot be deleted' USING ERRCODE = '55000';
                END IF;

                IF OLD.finished_at IS NULL AND NEW.finished_at IS NULL THEN
                    IF OLD.mode IS DISTINCT FROM 'execute'
                        OR OLD.request_started_at IS NOT NULL
                        OR NEW.request_started_at IS NULL
                        OR NEW.response_received_at IS NOT NULL
                        OR (to_jsonb(NEW) - 'request_started_at') IS DISTINCT FROM (to_jsonb(OLD) - 'request_started_at')
                        OR NOT EXISTS (
                            SELECT 1
                            FROM integration_operations
                            WHERE id = NEW.operation_id
                                AND active_attempt_id = NEW.id
                                AND status = 'processing'
                                AND max_remote_writes = 1
                                AND effect_state = 'possibly_applied'
                                AND request_started_at = NEW.request_started_at
                                AND lease_owner = NEW.worker_identity
                                AND lease_token_sha256 = NEW.lease_token_sha256
                        ) THEN
                        RAISE EXCEPTION 'integration operation effect boundary marker is invalid' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.finished_at IS NOT NULL
                    OR NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NEW.attempt_no IS DISTINCT FROM OLD.attempt_no
                    OR NEW.mode IS DISTINCT FROM OLD.mode
                    OR NEW.effect_state_before IS DISTINCT FROM OLD.effect_state_before
                    OR NEW.started_at IS DISTINCT FROM OLD.started_at
                    OR NEW.worker_identity IS DISTINCT FROM OLD.worker_identity
                    OR NEW.lease_token_sha256 IS DISTINCT FROM OLD.lease_token_sha256
                    OR NEW.request_started_at IS DISTINCT FROM OLD.request_started_at
                    OR (OLD.response_received_at IS NOT NULL AND NEW.response_received_at IS DISTINCT FROM OLD.response_received_at)
                    OR NEW.finished_at IS NULL THEN
                    RAISE EXCEPTION 'integration operation attempt may be finalized exactly once' USING ERRCODE = '55000';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }
};
