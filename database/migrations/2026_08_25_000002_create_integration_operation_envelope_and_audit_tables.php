<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_operation_payloads', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('operation_id', 26);
            $table->unsignedInteger('payload_revision');
            $table->unsignedSmallInteger('payload_key_version')->nullable();
            $table->string('payload_cipher', 32)->nullable();
            $table->text('payload_ciphertext')->nullable();
            $table->char('payload_ciphertext_sha256', 64)->nullable();
            $table->char('payload_fingerprint_hmac', 64);
            $table->unsignedSmallInteger('hmac_key_version');
            $table->unsignedSmallInteger('payload_schema_version');
            $table->unsignedSmallInteger('context_key_version')->nullable();
            $table->string('context_cipher', 32)->nullable();
            $table->text('context_ciphertext')->nullable();
            $table->char('context_ciphertext_sha256', 64)->nullable();
            $table->unsignedSmallInteger('context_schema_version');
            $table->char('context_lookup_hmac', 64)->nullable();
            $table->char('correlation_id_hmac', 64)->nullable();
            $table->string('created_by_actor', 128);
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('payload_pruned_at', precision: 6)->nullable();

            $table->foreign('operation_id', 'io_payloads_operation_fk')
                ->references('id')
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->unique(['operation_id', 'payload_revision'], 'io_payloads_operation_revision_unique');
        });

        Schema::create('integration_operation_results', function (Blueprint $table): void {
            $table->char('operation_id', 26)->primary();
            $table->string('result_type', 191);
            $table->unsignedSmallInteger('result_schema_version');
            $table->unsignedSmallInteger('result_key_version');
            $table->string('result_cipher', 32);
            $table->text('result_ciphertext');
            $table->char('result_ciphertext_sha256', 64);
            $table->timestampTz('created_at', precision: 6);

            $table->foreign('operation_id', 'io_results_operation_fk')
                ->references('id')
                ->on('integration_operations')
                ->restrictOnDelete();
        });

        Schema::create('integration_operation_attempts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('operation_id', 26);
            $table->unsignedInteger('attempt_no');
            $table->string('mode', 32);
            $table->string('safe_outcome_category', 64)->nullable();
            $table->string('effect_state_before', 32);
            $table->string('effect_state_after', 32)->nullable();
            $table->timestampTz('started_at', precision: 6);
            $table->timestampTz('finished_at', precision: 6)->nullable();
            $table->timestampTz('retry_after_at', precision: 6)->nullable();
            $table->timestampTz('reconcile_after_at', precision: 6)->nullable();
            $table->string('transport', 32)->nullable();
            $table->string('request_method', 16)->nullable();
            $table->string('target_template', 191)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestampTz('request_started_at', precision: 6)->nullable();
            $table->timestampTz('response_received_at', precision: 6)->nullable();
            $table->string('response_code', 64)->nullable();
            $table->string('provider_request_id', 191)->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('safe_metadata')->nullable();
            $table->string('worker_identity', 128);
            $table->char('lease_token_sha256', 64)->nullable();

            $table->foreign('operation_id', 'io_attempts_operation_fk')
                ->references('id')
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->unique(['operation_id', 'attempt_no'], 'io_attempts_operation_number_unique');
            $table->unique(['operation_id', 'id'], 'io_attempts_operation_id_unique');
            $table->index(['operation_id', 'started_at'], 'io_attempts_operation_started_idx');
        });

        Schema::table('integration_operations', function (Blueprint $table): void {
            $table->foreign(
                ['id', 'active_attempt_id'],
                'io_operations_active_attempt_fk',
            )->references(['operation_id', 'id'])
                ->on('integration_operation_attempts')
                ->restrictOnDelete();
            $table->foreign(
                ['id', 'last_attempt_id'],
                'io_operations_last_attempt_fk',
            )->references(['operation_id', 'id'])
                ->on('integration_operation_attempts')
                ->restrictOnDelete();
        });

        Schema::create('integration_operation_transitions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('operation_id', 26);
            $table->unsignedInteger('sequence');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('from_disposition', 32)->nullable();
            $table->string('to_disposition', 32);
            $table->string('from_effect_state', 32)->nullable();
            $table->string('to_effect_state', 32);
            $table->string('reason_code', 128);
            $table->string('actor_category', 32);
            $table->char('actor_reference_hmac', 64)->nullable();
            $table->unsignedSmallInteger('actor_hmac_key_version')->nullable();
            $table->unsignedBigInteger('expected_row_version')->nullable();
            $table->unsignedBigInteger('resulting_row_version');
            $table->timestampTz('created_at', precision: 6);

            $table->foreign('operation_id', 'io_transitions_operation_fk')
                ->references('id')
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->unique(['operation_id', 'sequence'], 'io_transitions_operation_sequence_unique');
            $table->index(['operation_id', 'created_at'], 'io_transitions_operation_created_idx');
        });

        $this->addPostgresConstraintsAndGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        Schema::dropIfExists('integration_operation_transitions');
        Schema::table('integration_operations', function (Blueprint $table): void {
            $table->dropForeign('io_operations_active_attempt_fk');
            $table->dropForeign('io_operations_last_attempt_fk');
        });
        Schema::dropIfExists('integration_operation_attempts');
        Schema::dropIfExists('integration_operation_results');
        Schema::dropIfExists('integration_operation_payloads');

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'io_assert_attempt_boundary_coherent',
            'io_assert_operation_boundary_coherent',
            'io_guard_operation_boundary_marker',
            'io_guard_transition_append_only',
            'io_guard_attempt_finalize_once',
            'io_guard_result_semantic_immutable',
            'io_guard_payload_semantic_immutable',
        ] as $function) {
            $connection->statement("DROP FUNCTION IF EXISTS {$function}() CASCADE");
        }
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

    private function addPostgresConstraintsAndGuards(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $statements = [
            <<<'SQL'
                ALTER TABLE integration_operation_payloads
                ADD CONSTRAINT io_payloads_envelopes_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND payload_revision > 0
                    AND hmac_key_version > 0
                    AND payload_schema_version > 0
                    AND context_schema_version > 0
                    AND (payload_key_version IS NULL OR payload_key_version > 0)
                    AND context_key_version > 0
                    AND (
                        (payload_pruned_at IS NULL
                            AND num_nonnulls(payload_key_version, payload_cipher, payload_ciphertext, payload_ciphertext_sha256) = 4
                        )
                        OR (payload_pruned_at IS NOT NULL
                            AND payload_key_version IS NULL AND payload_cipher IS NULL AND payload_ciphertext IS NULL AND payload_ciphertext_sha256 IS NULL)
                    )
                    AND num_nonnulls(context_key_version, context_cipher, context_ciphertext, context_ciphertext_sha256) = 4
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_results
                ADD CONSTRAINT io_results_envelope_check CHECK (
                    operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND result_schema_version > 0
                    AND result_key_version > 0
                    AND num_nonnulls(result_key_version, result_cipher, result_ciphertext, result_ciphertext_sha256) = 4
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_attempts
                ADD CONSTRAINT io_attempts_bounded_safe_metadata_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND attempt_no > 0
                    AND mode IN ('execute', 'reconcile', 'dispatch', 'recovery', 'projection', 'operator')
                    AND effect_state_before IN ('not_started', 'possibly_applied', 'not_applied', 'applied')
                    AND (effect_state_after IS NULL OR effect_state_after IN ('not_started', 'possibly_applied', 'not_applied', 'applied'))
                    AND (safe_metadata IS NULL OR octet_length(safe_metadata) <= 4096)
                    AND (lease_token_sha256 IS NULL OR lease_token_sha256 ~ '^[0-9a-f]{64}$')
                    AND ((mode IN ('execute', 'reconcile')) = (lease_token_sha256 IS NOT NULL))
                    AND ((finished_at IS NULL) = (safe_outcome_category IS NULL))
                    AND ((finished_at IS NULL) = (effect_state_after IS NULL))
                    AND (response_received_at IS NULL OR request_started_at IS NOT NULL)
                    AND (response_received_at IS NULL OR request_started_at <= response_received_at)
                    AND ((safe_outcome_category = 'deferred') = (mode = 'recovery' AND retry_after_at IS NOT NULL))
                    AND (retry_after_at IS NULL OR (mode = 'recovery' AND safe_outcome_category = 'deferred'))
                    AND (retry_after_at IS NULL OR (finished_at IS NOT NULL AND retry_after_at > finished_at))
                )
                SQL,
            <<<'SQL'
                CREATE UNIQUE INDEX io_attempts_one_open_per_operation_unique
                ON integration_operation_attempts (operation_id)
                WHERE finished_at IS NULL
                SQL,
            <<<'SQL'
                CREATE INDEX io_attempts_recovery_backoff_idx
                ON integration_operation_attempts (operation_id, retry_after_at)
                WHERE mode = 'recovery' AND safe_outcome_category = 'deferred' AND retry_after_at IS NOT NULL
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_transitions
                ADD CONSTRAINT io_transitions_lifecycle_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND sequence > 0
                    AND to_status IN ('pending', 'processing', 'retry_wait', 'uncertain', 'reconciling', 'manual_review', 'succeeded', 'failed', 'cancelled')
                    AND (from_status IS NULL OR from_status IN ('pending', 'processing', 'retry_wait', 'uncertain', 'reconciling', 'manual_review', 'succeeded', 'failed', 'cancelled'))
                    AND to_disposition IN ('in_progress', 'requires_manual_review', 'succeeded', 'failed', 'cancelled')
                    AND (from_disposition IS NULL OR from_disposition IN ('in_progress', 'requires_manual_review', 'succeeded', 'failed', 'cancelled'))
                    AND to_effect_state IN ('not_started', 'possibly_applied', 'not_applied', 'applied')
                    AND (from_effect_state IS NULL OR from_effect_state IN ('not_started', 'possibly_applied', 'not_applied', 'applied'))
                    AND resulting_row_version > 0
                    AND (
                        (sequence = 1
                            AND from_status IS NULL
                            AND from_disposition IS NULL
                            AND from_effect_state IS NULL
                            AND expected_row_version IS NULL
                            AND resulting_row_version = 1)
                        OR (sequence > 1
                            AND from_status IS NOT NULL
                            AND from_disposition IS NOT NULL
                            AND from_effect_state IS NOT NULL
                            AND expected_row_version IS NOT NULL
                            AND resulting_row_version = expected_row_version + 1)
                    )
                    AND ((actor_reference_hmac IS NULL) = (actor_hmac_key_version IS NULL))
                    AND (actor_hmac_key_version IS NULL OR actor_hmac_key_version > 0)
                    AND (
                        (to_status IN ('pending', 'processing', 'retry_wait', 'uncertain', 'reconciling') AND to_disposition = 'in_progress')
                        OR (to_status = 'manual_review' AND to_disposition = 'requires_manual_review')
                        OR (to_status = 'succeeded' AND to_disposition = 'succeeded')
                        OR (to_status = 'failed' AND to_disposition = 'failed')
                        OR (to_status = 'cancelled' AND to_disposition = 'cancelled')
                    )
                    AND (to_status <> 'failed' OR to_effect_state IN ('not_started', 'not_applied'))
                    AND (to_status <> 'cancelled' OR to_effect_state = 'not_started')
                    AND (to_status <> 'succeeded' OR to_effect_state IN ('not_started', 'applied'))
                    AND (to_effect_state <> 'possibly_applied' OR to_status NOT IN ('succeeded', 'failed', 'cancelled'))
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_transition_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'integration operation transitions are append-only' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_transitions_append_only
                BEFORE UPDATE OR DELETE ON integration_operation_transitions
                FOR EACH ROW EXECUTE FUNCTION io_guard_transition_append_only()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_payload_semantic_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation payloads cannot be deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                        OR NEW.payload_revision IS DISTINCT FROM OLD.payload_revision
                        OR NEW.payload_fingerprint_hmac IS DISTINCT FROM OLD.payload_fingerprint_hmac
                        OR NEW.hmac_key_version IS DISTINCT FROM OLD.hmac_key_version
                        OR NEW.payload_schema_version IS DISTINCT FROM OLD.payload_schema_version
                        OR NEW.context_schema_version IS DISTINCT FROM OLD.context_schema_version
                        OR NEW.context_lookup_hmac IS DISTINCT FROM OLD.context_lookup_hmac
                        OR NEW.correlation_id_hmac IS DISTINCT FROM OLD.correlation_id_hmac
                        OR NEW.created_by_actor IS DISTINCT FROM OLD.created_by_actor
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'integration operation payload semantics are immutable' USING ERRCODE = '55000';
                    END IF;

                    IF OLD.payload_pruned_at IS NOT NULL
                        AND (NEW.payload_pruned_at IS DISTINCT FROM OLD.payload_pruned_at
                            OR NEW.payload_key_version IS NOT NULL
                            OR NEW.payload_cipher IS NOT NULL
                            OR NEW.payload_ciphertext IS NOT NULL
                            OR NEW.payload_ciphertext_sha256 IS NOT NULL) THEN
                        RAISE EXCEPTION 'a pruned integration operation payload cannot be restored' USING ERRCODE = '55000';
                    END IF;

                    IF (
                            NEW.payload_key_version IS DISTINCT FROM OLD.payload_key_version
                            OR NEW.payload_cipher IS DISTINCT FROM OLD.payload_cipher
                            OR NEW.payload_ciphertext IS DISTINCT FROM OLD.payload_ciphertext
                            OR NEW.payload_ciphertext_sha256 IS DISTINCT FROM OLD.payload_ciphertext_sha256
                            OR NEW.context_key_version IS DISTINCT FROM OLD.context_key_version
                            OR NEW.context_cipher IS DISTINCT FROM OLD.context_cipher
                            OR NEW.context_ciphertext IS DISTINCT FROM OLD.context_ciphertext
                            OR NEW.context_ciphertext_sha256 IS DISTINCT FROM OLD.context_ciphertext_sha256
                        )
                        AND NOT (
                            OLD.payload_pruned_at IS NULL
                            AND NEW.payload_pruned_at IS NOT NULL
                            AND NEW.payload_key_version IS NULL
                            AND NEW.payload_cipher IS NULL
                            AND NEW.payload_ciphertext IS NULL
                            AND NEW.payload_ciphertext_sha256 IS NULL
                            AND NEW.context_key_version IS NOT DISTINCT FROM OLD.context_key_version
                            AND NEW.context_cipher IS NOT DISTINCT FROM OLD.context_cipher
                            AND NEW.context_ciphertext IS NOT DISTINCT FROM OLD.context_ciphertext
                            AND NEW.context_ciphertext_sha256 IS NOT DISTINCT FROM OLD.context_ciphertext_sha256
                        )
                        AND current_setting('integration_operations.reencryption', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'integration operation envelope re-encryption requires the controlled path' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_payloads_semantic_immutable
                BEFORE UPDATE OR DELETE ON integration_operation_payloads
                FOR EACH ROW EXECUTE FUNCTION io_guard_payload_semantic_immutable()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_result_semantic_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation results cannot be deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.operation_id IS DISTINCT FROM OLD.operation_id
                        OR NEW.result_type IS DISTINCT FROM OLD.result_type
                        OR NEW.result_schema_version IS DISTINCT FROM OLD.result_schema_version
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'integration operation result semantics are immutable' USING ERRCODE = '55000';
                    END IF;

                    IF (
                        NEW.result_key_version IS DISTINCT FROM OLD.result_key_version
                        OR NEW.result_cipher IS DISTINCT FROM OLD.result_cipher
                        OR NEW.result_ciphertext IS DISTINCT FROM OLD.result_ciphertext
                        OR NEW.result_ciphertext_sha256 IS DISTINCT FROM OLD.result_ciphertext_sha256
                    ) AND current_setting('integration_operations.reencryption', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'integration operation result re-encryption requires the controlled path' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_results_semantic_immutable
                BEFORE UPDATE OR DELETE ON integration_operation_results
                FOR EACH ROW EXECUTE FUNCTION io_guard_result_semantic_immutable()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_operation_boundary_marker() RETURNS trigger AS $$
                BEGIN
                    IF NEW.request_started_at IS NOT DISTINCT FROM OLD.request_started_at THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.request_started_at IS NOT NULL
                        OR NEW.request_started_at IS NULL
                        OR OLD.status <> 'processing'
                        OR NEW.status <> 'processing'
                        OR OLD.effect_state <> 'not_started'
                        OR NEW.effect_state <> 'possibly_applied'
                        OR NEW.max_remote_writes <> 1
                        OR OLD.active_attempt_id IS NULL
                        OR NEW.active_attempt_id IS DISTINCT FROM OLD.active_attempt_id
                        OR NEW.lease_owner IS DISTINCT FROM OLD.lease_owner
                        OR NEW.lease_token_sha256 IS DISTINCT FROM OLD.lease_token_sha256
                        OR NEW.lease_acquired_at IS DISTINCT FROM OLD.lease_acquired_at
                        OR NEW.lease_heartbeat_at IS DISTINCT FROM NEW.request_started_at
                        OR NEW.lease_expires_at <= NEW.request_started_at
                        OR NEW.row_version <> OLD.row_version + 1
                        OR (to_jsonb(NEW) - ARRAY[
                            'effect_state',
                            'request_started_at',
                            'lease_heartbeat_at',
                            'lease_expires_at',
                            'row_version',
                            'updated_at'
                        ]) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY[
                            'effect_state',
                            'request_started_at',
                            'lease_heartbeat_at',
                            'lease_expires_at',
                            'row_version',
                            'updated_at'
                        ]) THEN
                        RAISE EXCEPTION 'integration operation effect boundary marker is immutable' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_operations_boundary_marker_once
                BEFORE UPDATE ON integration_operations
                FOR EACH ROW EXECUTE FUNCTION io_guard_operation_boundary_marker()
                SQL,
            <<<'SQL'
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
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_attempts_finalize_once
                BEFORE UPDATE OR DELETE ON integration_operation_attempts
                FOR EACH ROW EXECUTE FUNCTION io_guard_attempt_finalize_once()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_operation_boundary_coherent() RETURNS trigger AS $$
                DECLARE
                    operation_row integration_operations%ROWTYPE;
                    attempt_mode text;
                    attempt_request_started_at timestamptz;
                    attempt_finished_at timestamptz;
                    attempt_worker_identity varchar(191);
                    attempt_lease_token_sha256 char(64);
                BEGIN
                    SELECT * INTO operation_row
                    FROM integration_operations
                    WHERE id = NEW.id;

                    IF NOT FOUND THEN
                        RETURN NULL;
                    END IF;

                    IF operation_row.active_attempt_id IS NULL THEN
                        IF EXISTS (
                            SELECT 1
                            FROM integration_operation_attempts
                            WHERE operation_id = operation_row.id
                                AND finished_at IS NULL
                        ) THEN
                            RAISE EXCEPTION 'integration operation open attempt requires an active pointer' USING ERRCODE = '23514';
                        END IF;

                        RETURN NULL;
                    END IF;

                    SELECT mode, request_started_at, finished_at, worker_identity, lease_token_sha256
                    INTO attempt_mode, attempt_request_started_at, attempt_finished_at,
                        attempt_worker_identity, attempt_lease_token_sha256
                    FROM integration_operation_attempts
                    WHERE operation_id = operation_row.id
                        AND id = operation_row.active_attempt_id;

                    IF NOT FOUND OR attempt_finished_at IS NOT NULL THEN
                        RAISE EXCEPTION 'integration operation active effect boundary attempt is invalid' USING ERRCODE = '23514';
                    END IF;

                    IF attempt_mode NOT IN ('execute', 'reconcile')
                        OR (operation_row.status = 'processing' AND attempt_mode <> 'execute')
                        OR (operation_row.status = 'reconciling' AND attempt_mode <> 'reconcile')
                        OR operation_row.status NOT IN ('processing', 'reconciling')
                        OR operation_row.lease_owner IS DISTINCT FROM attempt_worker_identity
                        OR operation_row.lease_token_sha256 IS DISTINCT FROM attempt_lease_token_sha256 THEN
                        RAISE EXCEPTION 'integration operation active attempt mode is invalid' USING ERRCODE = '23514';
                    END IF;

                    IF attempt_mode = 'reconcile' THEN
                        RETURN NULL;
                    END IF;

                    IF operation_row.request_started_at IS DISTINCT FROM attempt_request_started_at THEN
                        RAISE EXCEPTION 'integration operation active effect boundary markers are inconsistent' USING ERRCODE = '23514';
                    END IF;

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_operations_boundary_coherent
                AFTER INSERT OR UPDATE ON integration_operations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_operation_boundary_coherent()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_attempt_boundary_coherent() RETURNS trigger AS $$
                DECLARE
                    operation_request_started_at timestamptz;
                    operation_active_attempt_id char(26);
                BEGIN
                    SELECT request_started_at, active_attempt_id
                    INTO operation_request_started_at, operation_active_attempt_id
                    FROM integration_operations
                    WHERE id = NEW.operation_id;

                    IF NOT FOUND THEN
                        RETURN NULL;
                    END IF;

                    IF NEW.finished_at IS NOT NULL THEN
                        IF operation_active_attempt_id IS NOT DISTINCT FROM NEW.id THEN
                            RAISE EXCEPTION 'integration operation finished attempt cannot remain active' USING ERRCODE = '23514';
                        END IF;

                        RETURN NULL;
                    END IF;

                    IF NEW.mode NOT IN ('execute', 'reconcile')
                        OR operation_active_attempt_id IS DISTINCT FROM NEW.id THEN
                        RAISE EXCEPTION 'integration operation open attempt is not the active lease attempt' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.mode = 'execute'
                        AND operation_request_started_at IS DISTINCT FROM NEW.request_started_at THEN
                        RAISE EXCEPTION 'integration operation attempt effect boundary marker is inconsistent' USING ERRCODE = '23514';
                    END IF;

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_attempts_boundary_coherent
                AFTER INSERT OR UPDATE ON integration_operation_attempts
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_attempt_boundary_coherent()
                SQL,
        ];

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }
    }
};
