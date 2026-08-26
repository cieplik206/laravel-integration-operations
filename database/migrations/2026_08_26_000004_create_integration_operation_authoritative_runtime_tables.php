<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_operation_terminal_outcomes', function (Blueprint $table): void {
            $table->string('provider', 64);
            $table->string('operation_type', 191);
            $table->unsignedSmallInteger('handler_version');
            $table->string('status', 32);
            $table->string('effect_state', 32);
            $table->string('result_availability', 32);
            $table->string('proof_kind', 32);
            $table->timestampTz('created_at', precision: 6);

            $table->primary([
                'provider',
                'operation_type',
                'handler_version',
                'status',
                'effect_state',
                'result_availability',
                'proof_kind',
            ], 'io_terminal_outcomes_primary');
        });

        Schema::create('integration_operation_write_activations', function (Blueprint $table): void {
            $table->string('provider', 64);
            $table->string('operation_type', 191);
            $table->unsignedSmallInteger('handler_version');
            $table->string('activation_slot', 128);
            $table->string('activation', 32);
            $table->timestampTz('created_at', precision: 6);

            $table->primary([
                'provider',
                'operation_type',
                'handler_version',
                'activation_slot',
            ], 'io_write_activations_primary');
        });

        Schema::create('integration_operation_authoritative_states', function (Blueprint $table): void {
            $table->char('operation_id', 26)->primary();
            $table->unsignedSmallInteger('contract_version');
            $table->string('initial_lane', 32);
            $table->string('write_activation_slot', 128);
            $table->string('poll_purpose', 32)->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestampTz('poll_deadline_at', precision: 6)->nullable();
            $table->timestampTz('next_poll_at', precision: 6)->nullable();
            $table->timestampTz('last_polled_at', precision: 6)->nullable();
            $table->string('result_availability', 32)->default('not_ready');
            $table->string('terminal_proof_kind', 32)->nullable();
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('updated_at', precision: 6);

            $table->foreign('operation_id', 'io_authoritative_states_operation_fk')
                ->references('id')
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->index(
                ['next_poll_at', 'operation_id'],
                'io_authoritative_states_poll_due_idx',
            );
        });

        Schema::create('integration_operation_dispatch_cursors', function (Blueprint $table): void {
            $table->string('provider', 64);
            $table->string('connection_key', 128);
            $table->string('lane', 32);
            $table->smallInteger('last_priority')->nullable();
            $table->timestampTz('last_due_at', precision: 6)->nullable();
            $table->char('last_operation_id', 26)->nullable();
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('updated_at', precision: 6);

            $table->primary(
                ['provider', 'connection_key', 'lane'],
                'io_dispatch_cursors_primary',
            );
        });

        Schema::create('integration_operation_projection_states', function (Blueprint $table): void {
            $table->char('operation_id', 26);
            $table->string('projection_kind', 32);
            $table->string('target_id', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->unsignedBigInteger('source_row_version');
            $table->unsignedBigInteger('applied_row_version')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at', precision: 6)->nullable();
            $table->string('lease_owner', 128)->nullable();
            $table->char('lease_token_sha256', 64)->nullable();
            $table->timestampTz('lease_expires_at', precision: 6)->nullable();
            $table->string('last_safe_failure_code', 64)->nullable();
            $table->string('last_safe_failure_summary', 512)->nullable();
            $table->timestampTz('projected_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('updated_at', precision: 6);

            $table->primary(
                ['operation_id', 'projection_kind', 'target_id'],
                'io_projection_states_primary',
            );
            $table->foreign('operation_id', 'io_projection_states_operation_fk')
                ->references('id')
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->index(
                ['next_attempt_at', 'operation_id'],
                'io_projection_states_due_idx',
            );
        });

        Schema::create('integration_operation_relations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('provider', 64);
            $table->string('connection_key', 128);
            $table->char('parent_operation_id', 26);
            $table->char('child_operation_id', 26);
            $table->string('purpose', 32);
            $table->string('slot', 128);
            $table->timestampTz('created_at', precision: 6);

            $table->foreign(
                ['provider', 'connection_key', 'parent_operation_id'],
                'io_relations_parent_operation_fk',
            )->references(['provider', 'connection_key', 'id'])
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->foreign(
                ['provider', 'connection_key', 'child_operation_id'],
                'io_relations_child_operation_fk',
            )->references(['provider', 'connection_key', 'id'])
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->unique(
                ['parent_operation_id', 'purpose', 'slot'],
                'io_relations_parent_purpose_slot_unique',
            );
            $table->unique(
                ['child_operation_id', 'purpose'],
                'io_relations_child_purpose_unique',
            );
            $table->index(
                ['provider', 'connection_key', 'parent_operation_id'],
                'io_relations_scope_parent_idx',
            );
        });

        $this->addPostgresConstraintsAndGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $this->assertRollbackIsSafe();
        }

        Schema::dropIfExists('integration_operation_relations');
        Schema::dropIfExists('integration_operation_projection_states');
        Schema::dropIfExists('integration_operation_dispatch_cursors');
        Schema::dropIfExists('integration_operation_authoritative_states');
        Schema::dropIfExists('integration_operation_write_activations');
        Schema::dropIfExists('integration_operation_terminal_outcomes');

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'io_assert_authoritative_operation_contract',
            'io_assert_authoritative_state_contract',
            'io_assert_authoritative_contract',
            'io_guard_operation_relation_immutable',
            'io_guard_projection_state',
            'io_guard_dispatch_cursor',
            'io_guard_authoritative_state',
            'io_guard_authoritative_definition_row',
        ] as $function) {
            $connection->statement("DROP FUNCTION IF EXISTS {$function} CASCADE");
        }

        foreach ($this->legacyRuntimeStatements() as $statement) {
            $connection->statement($statement);
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

        foreach ($this->postgresStatements() as $statement) {
            $connection->statement($statement);
        }
    }

    private function assertRollbackIsSafe(): void
    {
        foreach ([
            'integration_operation_terminal_outcomes',
            'integration_operation_write_activations',
            'integration_operation_authoritative_states',
            'integration_operation_dispatch_cursors',
            'integration_operation_projection_states',
            'integration_operation_relations',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::getConnection()->table($table)->exists()) {
                throw new RuntimeException('Authoritative integration operation runtime data blocks migration rollback.');
            }
        }

        $connection = Schema::getConnection();
        $hasPollingOperations = $connection->table('integration_operations')
            ->whereIn('status', ['poll_wait', 'polling'])
            ->exists();
        $hasPollingAttempts = $connection->table('integration_operation_attempts')
            ->where('mode', 'poll')
            ->exists();
        $hasPollingTransitions = $connection->table('integration_operation_transitions')
            ->where(function ($query): void {
                $query->whereIn('from_status', ['poll_wait', 'polling'])
                    ->orWhereIn('to_status', ['poll_wait', 'polling']);
            })
            ->exists();

        if ($hasPollingOperations || $hasPollingAttempts || $hasPollingTransitions) {
            throw new RuntimeException('Authoritative polling lifecycle data blocks migration rollback.');
        }
    }

    /** @return list<string> */
    private function legacyRuntimeStatements(): array
    {
        return [
            'ALTER TABLE integration_operations DROP CONSTRAINT io_operations_lifecycle_check',
            <<<'SQL'
                ALTER TABLE integration_operations
                ADD CONSTRAINT io_operations_lifecycle_check CHECK (
                    max_remote_writes IN (0, 1)
                    AND intent_generation > 0
                    AND current_payload_revision > 0
                    AND payload_schema_version > 0
                    AND handler_version > 0
                    AND result_schema_version > 0
                    AND row_version > 0
                    AND attempts >= 0
                    AND reconcile_attempts >= 0
                    AND dispatch_attempts >= 0
                    AND writer_generation > 0
                    AND status IN ('pending', 'processing', 'retry_wait', 'uncertain', 'reconciling', 'manual_review', 'succeeded', 'failed', 'cancelled')
                    AND disposition IN ('in_progress', 'requires_manual_review', 'succeeded', 'failed', 'cancelled')
                    AND effect_state IN ('not_started', 'possibly_applied', 'not_applied', 'applied')
                    AND owner_mode_at_accept IN ('off', 'shadow_read', 'canary_write', 'on')
                    AND (
                        (status IN ('pending', 'processing', 'retry_wait', 'uncertain', 'reconciling') AND disposition = 'in_progress')
                        OR (status = 'manual_review' AND disposition = 'requires_manual_review')
                        OR (status = 'succeeded' AND disposition = 'succeeded')
                        OR (status = 'failed' AND disposition = 'failed')
                        OR (status = 'cancelled' AND disposition = 'cancelled')
                    )
                    AND ((status IN ('succeeded', 'failed', 'cancelled')) = (completed_at IS NOT NULL))
                    AND (max_remote_writes = 1 OR effect_state = 'not_started')
                    AND (status NOT IN ('pending', 'retry_wait') OR effect_state = 'not_started')
                    AND (status <> 'failed' OR effect_state IN ('not_started', 'not_applied'))
                    AND (status <> 'cancelled' OR effect_state = 'not_started')
                    AND (effect_state <> 'possibly_applied' OR status NOT IN ('succeeded', 'failed', 'cancelled'))
                    AND (status <> 'succeeded' OR max_remote_writes = 0 OR effect_state = 'applied')
                    AND (
                        (request_started_at IS NULL AND effect_state = 'not_started')
                        OR (request_started_at IS NOT NULL AND max_remote_writes = 1 AND effect_state IN ('possibly_applied', 'not_applied', 'applied'))
                    )
                    AND (
                        (status IN ('processing', 'reconciling') AND
                            num_nonnulls(lease_owner, lease_token_sha256, lease_acquired_at, lease_heartbeat_at, lease_expires_at, active_attempt_id) = 6
                            AND lease_token_sha256 ~ '^[0-9a-f]{64}$'
                            AND lease_acquired_at <= lease_heartbeat_at
                            AND lease_heartbeat_at < lease_expires_at
                        )
                        OR (status NOT IN ('processing', 'reconciling')
                            AND num_nonnulls(lease_owner, lease_token_sha256, lease_acquired_at, lease_heartbeat_at, lease_expires_at, active_attempt_id) = 0)
                    )
                    AND (status NOT IN ('retry_wait', 'uncertain') OR next_attempt_at IS NOT NULL)
                    AND (active_attempt_id IS NULL OR last_attempt_id IS NOT NULL)
                    AND ((cohort_key_hmac IS NULL) = (owner_hmac_key_version IS NULL))
                    AND (owner_hmac_key_version IS NULL OR owner_hmac_key_version > 0)
                    AND ((last_safe_failure_code IS NULL) = (last_safe_failure_summary IS NULL))
                    AND (status <> 'failed' OR last_safe_failure_code IS NOT NULL)
                    AND (last_safe_failure_code IS NULL OR last_safe_failure_code ~ '^[a-z][a-z0-9_.-]{0,63}$')
                    AND (last_safe_failure_summary IS NULL OR octet_length(last_safe_failure_summary) BETWEEN 1 AND 512)
                )
                SQL,
            'ALTER TABLE integration_operation_attempts DROP CONSTRAINT io_attempts_bounded_safe_metadata_check',
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
            'ALTER TABLE integration_operation_transitions DROP CONSTRAINT io_transitions_lifecycle_check',
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
        ];
    }

    /** @return list<string> */
    private function postgresStatements(): array
    {
        return [
            <<<'SQL'
                ALTER TABLE integration_operation_terminal_outcomes
                ADD CONSTRAINT io_terminal_outcomes_shape_check CHECK (
                    provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND operation_type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$'
                    AND operation_type LIKE provider || '.%'
                    AND handler_version > 0
                    AND (
                        (status = 'succeeded' AND effect_state = 'not_started' AND result_availability = 'available'
                            AND proof_kind IN ('execute', 'poll', 'sealed_provider_evidence'))
                        OR (status = 'succeeded' AND effect_state = 'applied' AND result_availability = 'available'
                            AND proof_kind IN ('execute', 'poll', 'reconcile', 'sealed_provider_evidence'))
                        OR (status = 'failed' AND effect_state = 'not_started' AND result_availability = 'not_applicable'
                            AND proof_kind IN ('operator', 'pre_effect'))
                        OR (status = 'failed' AND effect_state = 'not_started' AND result_availability = 'available'
                            AND proof_kind IN ('poll', 'reconcile', 'sealed_provider_evidence'))
                        OR (status = 'failed' AND effect_state = 'not_applied' AND result_availability = 'not_applicable'
                            AND proof_kind = 'reconcile')
                        OR (status = 'failed' AND effect_state = 'applied' AND result_availability = 'available'
                            AND proof_kind IN ('poll', 'reconcile', 'sealed_provider_evidence'))
                        OR (status = 'cancelled' AND effect_state = 'not_started' AND result_availability = 'not_applicable'
                            AND proof_kind = 'operator')
                    )
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_write_activations
                ADD CONSTRAINT io_write_activations_shape_check CHECK (
                    provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND operation_type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$'
                    AND operation_type LIKE provider || '.%'
                    AND handler_version > 0
                    AND activation_slot ~ '^[a-z][a-z0-9_.:-]{0,127}$'
                    AND activation IN ('disabled', 'immediate_execute', 'poll_send_required')
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_authoritative_states
                ADD CONSTRAINT io_authoritative_states_shape_check CHECK (
                    operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND contract_version = 2
                    AND initial_lane IN ('execute', 'poll')
                    AND write_activation_slot ~ '^[a-z][a-z0-9_.:-]{0,127}$'
                    AND (poll_purpose IS NULL OR poll_purpose IN ('preflight', 'observation'))
                    AND poll_attempts >= 0
                    AND result_availability IN ('not_ready', 'available', 'not_applicable')
                    AND (terminal_proof_kind IS NULL OR terminal_proof_kind IN (
                        'execute', 'poll', 'reconcile', 'sealed_provider_evidence', 'operator', 'pre_effect'
                    ))
                    AND (
                        (poll_purpose IS NULL
                            AND poll_attempts = 0
                            AND poll_deadline_at IS NULL
                            AND next_poll_at IS NULL
                            AND last_polled_at IS NULL)
                        OR (poll_purpose IS NOT NULL
                            AND poll_deadline_at IS NOT NULL
                            AND next_poll_at IS NOT NULL
                            AND next_poll_at <= poll_deadline_at
                            AND ((poll_attempts = 0 AND last_polled_at IS NULL)
                                OR (poll_attempts > 0 AND last_polled_at IS NOT NULL)))
                    )
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_dispatch_cursors
                ADD CONSTRAINT io_dispatch_cursors_shape_check CHECK (
                    provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                    AND lane IN ('execute', 'poll', 'reconcile', 'projection')
                    AND generation > 0
                    AND (
                        num_nonnulls(last_priority, last_due_at, last_operation_id) = 0
                        OR (num_nonnulls(last_priority, last_due_at, last_operation_id) = 3
                            AND last_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    )
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_projection_states
                ADD CONSTRAINT io_projection_states_shape_check CHECK (
                    operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND projection_kind IN ('terminal', 'observation')
                    AND target_id ~ '^[a-z][a-z0-9_.:-]{0,63}$'
                    AND schema_version > 0
                    AND source_row_version > 0
                    AND (applied_row_version IS NULL OR applied_row_version BETWEEN 1 AND source_row_version)
                    AND attempts >= 0
                    AND (
                        num_nonnulls(lease_owner, lease_token_sha256, lease_expires_at) = 0
                        OR (num_nonnulls(lease_owner, lease_token_sha256, lease_expires_at) = 3
                            AND lease_token_sha256 ~ '^[a-f0-9]{64}$')
                    )
                    AND ((last_safe_failure_code IS NULL) = (last_safe_failure_summary IS NULL))
                    AND (last_safe_failure_code IS NULL OR last_safe_failure_code ~ '^[a-z][a-z0-9_.-]{0,63}$')
                    AND (last_safe_failure_summary IS NULL OR octet_length(last_safe_failure_summary) BETWEEN 1 AND 512)
                    AND (projected_at IS NULL OR applied_row_version = source_row_version)
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_relations
                ADD CONSTRAINT io_relations_shape_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                    AND parent_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND child_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND parent_operation_id <> child_operation_id
                    AND purpose = 'compensation'
                    AND slot ~ '^[a-z][a-z0-9_.:-]{0,127}$'
                )
                SQL,
            ...$this->lifecycleConstraintStatements(),
            ...$this->guardStatements(),
        ];
    }

    /** @return list<string> */
    private function lifecycleConstraintStatements(): array
    {
        return [
            'ALTER TABLE integration_operations DROP CONSTRAINT io_operations_lifecycle_check',
            <<<'SQL'
                ALTER TABLE integration_operations
                ADD CONSTRAINT io_operations_lifecycle_check CHECK (
                    max_remote_writes IN (0, 1)
                    AND intent_generation > 0
                    AND current_payload_revision > 0
                    AND payload_schema_version > 0
                    AND handler_version > 0
                    AND result_schema_version > 0
                    AND row_version > 0
                    AND attempts >= 0
                    AND reconcile_attempts >= 0
                    AND dispatch_attempts >= 0
                    AND writer_generation > 0
                    AND status IN ('pending', 'processing', 'retry_wait', 'poll_wait', 'polling', 'uncertain', 'reconciling', 'manual_review', 'succeeded', 'failed', 'cancelled')
                    AND disposition IN ('in_progress', 'requires_manual_review', 'succeeded', 'failed', 'cancelled')
                    AND effect_state IN ('not_started', 'possibly_applied', 'not_applied', 'applied')
                    AND owner_mode_at_accept IN ('off', 'shadow_read', 'canary_write', 'on')
                    AND (
                        (status IN ('pending', 'processing', 'retry_wait', 'poll_wait', 'polling', 'uncertain', 'reconciling') AND disposition = 'in_progress')
                        OR (status = 'manual_review' AND disposition = 'requires_manual_review')
                        OR (status = 'succeeded' AND disposition = 'succeeded')
                        OR (status = 'failed' AND disposition = 'failed')
                        OR (status = 'cancelled' AND disposition = 'cancelled')
                    )
                    AND ((status IN ('succeeded', 'failed', 'cancelled')) = (completed_at IS NOT NULL))
                    AND (max_remote_writes = 1 OR effect_state = 'not_started')
                    AND (status NOT IN ('pending', 'retry_wait') OR effect_state = 'not_started')
                    AND (status <> 'failed' OR effect_state IN ('not_started', 'not_applied', 'applied'))
                    AND (status <> 'cancelled' OR effect_state = 'not_started')
                    AND (effect_state <> 'possibly_applied' OR status NOT IN ('succeeded', 'failed', 'cancelled'))
                    AND (status <> 'succeeded' OR effect_state IN ('not_started', 'applied'))
                    AND (
                        (request_started_at IS NULL AND effect_state = 'not_started')
                        OR (request_started_at IS NOT NULL AND max_remote_writes = 1 AND effect_state IN ('possibly_applied', 'not_applied', 'applied'))
                    )
                    AND (
                        (status IN ('processing', 'polling', 'reconciling') AND
                            num_nonnulls(lease_owner, lease_token_sha256, lease_acquired_at, lease_heartbeat_at, lease_expires_at, active_attempt_id) = 6
                            AND lease_token_sha256 ~ '^[0-9a-f]{64}$'
                            AND lease_acquired_at <= lease_heartbeat_at
                            AND lease_heartbeat_at < lease_expires_at
                        )
                        OR (status NOT IN ('processing', 'polling', 'reconciling')
                            AND num_nonnulls(lease_owner, lease_token_sha256, lease_acquired_at, lease_heartbeat_at, lease_expires_at, active_attempt_id) = 0)
                    )
                    AND (status NOT IN ('retry_wait', 'uncertain') OR next_attempt_at IS NOT NULL)
                    AND (active_attempt_id IS NULL OR last_attempt_id IS NOT NULL)
                    AND ((cohort_key_hmac IS NULL) = (owner_hmac_key_version IS NULL))
                    AND (owner_hmac_key_version IS NULL OR owner_hmac_key_version > 0)
                    AND ((last_safe_failure_code IS NULL) = (last_safe_failure_summary IS NULL))
                    AND (status <> 'failed' OR last_safe_failure_code IS NOT NULL)
                    AND (last_safe_failure_code IS NULL OR last_safe_failure_code ~ '^[a-z][a-z0-9_.-]{0,63}$')
                    AND (last_safe_failure_summary IS NULL OR octet_length(last_safe_failure_summary) BETWEEN 1 AND 512)
                )
                SQL,
            'ALTER TABLE integration_operation_attempts DROP CONSTRAINT io_attempts_bounded_safe_metadata_check',
            <<<'SQL'
                ALTER TABLE integration_operation_attempts
                ADD CONSTRAINT io_attempts_bounded_safe_metadata_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND attempt_no > 0
                    AND mode IN ('execute', 'poll', 'reconcile', 'dispatch', 'recovery', 'projection', 'operator')
                    AND effect_state_before IN ('not_started', 'possibly_applied', 'not_applied', 'applied')
                    AND (effect_state_after IS NULL OR effect_state_after IN ('not_started', 'possibly_applied', 'not_applied', 'applied'))
                    AND (safe_metadata IS NULL OR octet_length(safe_metadata) <= 4096)
                    AND (lease_token_sha256 IS NULL OR lease_token_sha256 ~ '^[0-9a-f]{64}$')
                    AND ((mode IN ('execute', 'poll', 'reconcile')) = (lease_token_sha256 IS NOT NULL))
                    AND ((finished_at IS NULL) = (safe_outcome_category IS NULL))
                    AND ((finished_at IS NULL) = (effect_state_after IS NULL))
                    AND (response_received_at IS NULL OR request_started_at IS NOT NULL)
                    AND (response_received_at IS NULL OR request_started_at <= response_received_at)
                    AND ((safe_outcome_category = 'deferred') = (mode = 'recovery' AND retry_after_at IS NOT NULL))
                    AND (retry_after_at IS NULL OR (mode = 'recovery' AND safe_outcome_category = 'deferred'))
                    AND (retry_after_at IS NULL OR (finished_at IS NOT NULL AND retry_after_at > finished_at))
                )
                SQL,
            'ALTER TABLE integration_operation_transitions DROP CONSTRAINT io_transitions_lifecycle_check',
            <<<'SQL'
                ALTER TABLE integration_operation_transitions
                ADD CONSTRAINT io_transitions_lifecycle_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND sequence > 0
                    AND to_status IN ('pending', 'processing', 'retry_wait', 'poll_wait', 'polling', 'uncertain', 'reconciling', 'manual_review', 'succeeded', 'failed', 'cancelled')
                    AND (from_status IS NULL OR from_status IN ('pending', 'processing', 'retry_wait', 'poll_wait', 'polling', 'uncertain', 'reconciling', 'manual_review', 'succeeded', 'failed', 'cancelled'))
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
                        (to_status IN ('pending', 'processing', 'retry_wait', 'poll_wait', 'polling', 'uncertain', 'reconciling') AND to_disposition = 'in_progress')
                        OR (to_status = 'manual_review' AND to_disposition = 'requires_manual_review')
                        OR (to_status = 'succeeded' AND to_disposition = 'succeeded')
                        OR (to_status = 'failed' AND to_disposition = 'failed')
                        OR (to_status = 'cancelled' AND to_disposition = 'cancelled')
                    )
                    AND (to_status <> 'failed' OR to_effect_state IN ('not_started', 'not_applied', 'applied'))
                    AND (to_status <> 'cancelled' OR to_effect_state = 'not_started')
                    AND (to_status <> 'succeeded' OR to_effect_state IN ('not_started', 'applied'))
                    AND (to_effect_state <> 'possibly_applied' OR to_status NOT IN ('succeeded', 'failed', 'cancelled'))
                )
                SQL,
        ];
    }

    /** @return list<string> */
    private function guardStatements(): array
    {
        return [
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

                    IF attempt_mode NOT IN ('execute', 'poll', 'reconcile')
                        OR (operation_row.status = 'processing' AND attempt_mode <> 'execute')
                        OR (operation_row.status = 'polling' AND attempt_mode <> 'poll')
                        OR (operation_row.status = 'reconciling' AND attempt_mode <> 'reconcile')
                        OR operation_row.status NOT IN ('processing', 'polling', 'reconciling')
                        OR operation_row.lease_owner IS DISTINCT FROM attempt_worker_identity
                        OR operation_row.lease_token_sha256 IS DISTINCT FROM attempt_lease_token_sha256 THEN
                        RAISE EXCEPTION 'integration operation active attempt mode is invalid' USING ERRCODE = '23514';
                    END IF;

                    IF attempt_mode IN ('poll', 'reconcile') THEN
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

                    IF NEW.mode NOT IN ('execute', 'poll', 'reconcile')
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
                CREATE OR REPLACE FUNCTION io_guard_authoritative_definition_row() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'integration operation authoritative definition rows are append-only' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_terminal_outcomes_append_only
                BEFORE UPDATE OR DELETE ON integration_operation_terminal_outcomes
                FOR EACH ROW EXECUTE FUNCTION io_guard_authoritative_definition_row()
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_write_activations_append_only
                BEFORE UPDATE OR DELETE ON integration_operation_write_activations
                FOR EACH ROW EXECUTE FUNCTION io_guard_authoritative_definition_row()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_authoritative_state() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation authoritative state cannot be deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.operation_id IS DISTINCT FROM OLD.operation_id
                        OR NEW.contract_version IS DISTINCT FROM OLD.contract_version
                        OR NEW.initial_lane IS DISTINCT FROM OLD.initial_lane
                        OR NEW.write_activation_slot IS DISTINCT FROM OLD.write_activation_slot
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.poll_attempts < OLD.poll_attempts
                        OR OLD.terminal_proof_kind IS NOT NULL
                            AND NEW.terminal_proof_kind IS DISTINCT FROM OLD.terminal_proof_kind
                        OR OLD.result_availability <> 'not_ready'
                            AND NEW.result_availability IS DISTINCT FROM OLD.result_availability THEN
                        RAISE EXCEPTION 'integration operation authoritative state update is invalid' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_authoritative_states_guarded
                BEFORE UPDATE OR DELETE ON integration_operation_authoritative_states
                FOR EACH ROW EXECUTE FUNCTION io_guard_authoritative_state()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_dispatch_cursor() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE'
                        OR NEW.provider IS DISTINCT FROM OLD.provider
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.lane IS DISTINCT FROM OLD.lane
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.generation <> OLD.generation + 1
                        OR NEW.updated_at <= OLD.updated_at THEN
                        RAISE EXCEPTION 'integration operation dispatch cursor update is invalid' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_dispatch_cursors_guarded
                BEFORE UPDATE OR DELETE ON integration_operation_dispatch_cursors
                FOR EACH ROW EXECUTE FUNCTION io_guard_dispatch_cursor()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_projection_state() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE'
                        OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                        OR NEW.projection_kind IS DISTINCT FROM OLD.projection_kind
                        OR NEW.target_id IS DISTINCT FROM OLD.target_id
                        OR NEW.schema_version IS DISTINCT FROM OLD.schema_version
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.source_row_version < OLD.source_row_version
                        OR NEW.attempts < OLD.attempts
                        OR OLD.projected_at IS NOT NULL AND NEW.projected_at IS DISTINCT FROM OLD.projected_at THEN
                        RAISE EXCEPTION 'integration operation projection state update is invalid' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_projection_states_guarded
                BEFORE UPDATE OR DELETE ON integration_operation_projection_states
                FOR EACH ROW EXECUTE FUNCTION io_guard_projection_state()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_operation_relation_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'integration operation relations are append-only' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_relations_append_only
                BEFORE UPDATE OR DELETE ON integration_operation_relations
                FOR EACH ROW EXECUTE FUNCTION io_guard_operation_relation_immutable()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_authoritative_contract(asserted_operation_id char(26)) RETURNS void AS $$
                DECLARE
                    operation_row integration_operations%ROWTYPE;
                    state_row integration_operation_authoritative_states%ROWTYPE;
                BEGIN
                    SELECT * INTO operation_row
                    FROM integration_operations
                    WHERE id = asserted_operation_id;

                    SELECT * INTO state_row
                    FROM integration_operation_authoritative_states
                    WHERE operation_id = asserted_operation_id;

                    IF NOT FOUND THEN
                        RETURN;
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1
                        FROM integration_operation_write_activations
                        WHERE provider = operation_row.provider
                            AND operation_type = operation_row.operation_type
                            AND handler_version = operation_row.handler_version
                            AND activation_slot = state_row.write_activation_slot
                    ) THEN
                        RAISE EXCEPTION 'integration operation payload selected an undeclared write activation slot' USING ERRCODE = '23514';
                    END IF;

                    IF operation_row.status IN ('succeeded', 'failed', 'cancelled') THEN
                        IF state_row.result_availability = 'not_ready'
                            OR state_row.terminal_proof_kind IS NULL
                            OR NOT EXISTS (
                                SELECT 1
                                FROM integration_operation_terminal_outcomes
                                WHERE provider = operation_row.provider
                                    AND operation_type = operation_row.operation_type
                                    AND handler_version = operation_row.handler_version
                                    AND status = operation_row.status
                                    AND effect_state = operation_row.effect_state
                                    AND result_availability = state_row.result_availability
                                    AND proof_kind = state_row.terminal_proof_kind
                            ) THEN
                            RAISE EXCEPTION 'integration operation terminal outcome is not definition-whitelisted' USING ERRCODE = '23514';
                        END IF;

                        IF operation_row.status = 'failed'
                            AND (operation_row.last_safe_failure_code IS NULL
                                OR operation_row.last_safe_failure_summary IS NULL) THEN
                            RAISE EXCEPTION 'integration operation failed result requires a safe failure' USING ERRCODE = '23514';
                        END IF;

                        IF state_row.result_availability = 'available'
                            AND NOT EXISTS (
                                SELECT 1
                                FROM integration_operation_results
                                WHERE operation_id = asserted_operation_id
                            ) THEN
                            RAISE EXCEPTION 'integration operation terminal result envelope is missing' USING ERRCODE = '23514';
                        END IF;

                        RETURN;
                    END IF;

                    IF state_row.result_availability <> 'not_ready'
                        OR state_row.terminal_proof_kind IS NOT NULL THEN
                        RAISE EXCEPTION 'integration operation nonterminal state exposes terminal evidence' USING ERRCODE = '23514';
                    END IF;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_authoritative_operation_contract() RETURNS trigger AS $$
                BEGIN
                    PERFORM io_assert_authoritative_contract(NEW.id);

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_operations_authoritative_contract
                AFTER INSERT OR UPDATE ON integration_operations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_authoritative_operation_contract()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_authoritative_state_contract() RETURNS trigger AS $$
                BEGIN
                    PERFORM io_assert_authoritative_contract(NEW.operation_id);

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_authoritative_states_contract
                AFTER INSERT OR UPDATE ON integration_operation_authoritative_states
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_authoritative_state_contract()
                SQL,
        ];
    }
};
