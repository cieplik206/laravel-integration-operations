<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_operation_intents', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('provider', 64);
            $table->string('connection_key', 128);
            $table->string('operation_type', 191);
            $table->string('resource_type', 128);
            $table->string('semantic_slot', 128);
            $table->string('local_type', 128)->nullable();
            $table->unsignedSmallInteger('local_id_key_version')->nullable();
            $table->string('local_id_cipher', 32)->nullable();
            $table->text('local_id_ciphertext')->nullable();
            $table->char('local_id_ciphertext_sha256', 64)->nullable();
            $table->char('local_reference_hmac', 64)->nullable();
            $table->char('intent_key_hmac', 64);
            $table->unsignedSmallInteger('hmac_key_version');
            $table->unsignedInteger('current_generation')->default(0);
            $table->char('current_operation_id', 26)->nullable();
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('updated_at', precision: 6);

            $table->index(
                ['provider', 'connection_key', 'operation_type', 'resource_type', 'semantic_slot'],
                'io_intents_scope_type_resource_slot_idx',
            );
            $table->unique(['provider', 'connection_key', 'id'], 'io_intents_scope_id_unique');
            $table->unique(
                ['provider', 'connection_key', 'id', 'operation_type', 'resource_type', 'semantic_slot'],
                'io_intents_scope_identity_unique',
            );
            $table->index(
                ['provider', 'connection_key', 'current_operation_id'],
                'io_intents_scope_current_operation_idx',
            );
        });

        Schema::create('integration_operation_lookup_keys', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('provider', 64);
            $table->string('connection_key', 128);
            $table->string('lookup_type', 32);
            $table->char('subject_id', 26);
            $table->char('intent_id', 26)->nullable();
            $table->char('operation_id', 26)->nullable();
            $table->unsignedSmallInteger('key_version');
            $table->char('digest', 64);
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('retired_at', precision: 6)->nullable();

            $table->unique(
                ['lookup_type', 'subject_id', 'key_version'],
                'io_lookup_type_subject_version_unique',
            );
            $table->foreign(
                ['provider', 'connection_key', 'intent_id'],
                'io_lookup_scope_intent_fk',
            )->references(['provider', 'connection_key', 'id'])
                ->on('integration_operation_intents')
                ->restrictOnDelete();
            $table->index(
                ['provider', 'connection_key', 'lookup_type', 'key_version', 'digest'],
                'io_lookup_scope_type_version_digest_idx',
            );
            $table->index(
                ['provider', 'connection_key', 'lookup_type', 'retired_at'],
                'io_lookup_scope_type_retired_idx',
            );
        });

        Schema::create('integration_operations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('intent_id', 26);
            $table->unsignedInteger('intent_generation');
            $table->char('supersedes_operation_id', 26)->nullable();
            $table->string('provider', 64);
            $table->string('connection_key', 128);
            $table->string('operation_type', 191);
            $table->string('resource_type', 128);
            $table->string('semantic_slot', 128);
            $table->char('intent_key_hmac', 64);
            $table->unsignedInteger('current_payload_revision')->default(1);
            $table->unsignedSmallInteger('payload_schema_version');
            $table->unsignedSmallInteger('handler_version');
            $table->unsignedSmallInteger('result_schema_version');
            $table->unsignedSmallInteger('max_remote_writes');
            $table->string('status', 32);
            $table->string('disposition', 32);
            $table->string('effect_state', 32);
            $table->unsignedBigInteger('row_version')->default(1);
            $table->smallInteger('priority')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('reconcile_attempts')->default(0);
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestampTz('last_dispatched_at', precision: 6)->nullable();
            $table->timestampTz('next_attempt_at', precision: 6)->nullable();
            $table->string('lease_owner', 128)->nullable();
            $table->char('lease_token_sha256', 64)->nullable();
            $table->timestampTz('lease_acquired_at', precision: 6)->nullable();
            $table->timestampTz('lease_heartbeat_at', precision: 6)->nullable();
            $table->timestampTz('lease_expires_at', precision: 6)->nullable();
            $table->unsignedInteger('writer_generation');
            $table->string('owner_mode_at_accept', 32);
            $table->char('cohort_key_hmac', 64)->nullable();
            $table->unsignedSmallInteger('owner_hmac_key_version')->nullable();
            $table->timestampTz('request_started_at', precision: 6)->nullable();
            $table->string('last_error_category', 64)->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->string('last_safe_failure_code', 64)->nullable();
            $table->string('last_safe_failure_summary', 512)->nullable();
            $table->char('active_attempt_id', 26)->nullable();
            $table->char('last_attempt_id', 26)->nullable();
            $table->timestampTz('accepted_at', precision: 6);
            $table->timestampTz('completed_at', precision: 6)->nullable();
            $table->timestampTz('tombstone_after_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('updated_at', precision: 6);

            $table->unique(['provider', 'connection_key', 'id'], 'io_operations_scope_id_unique');
            $table->foreign(
                ['provider', 'connection_key', 'intent_id'],
                'io_operations_scope_intent_fk',
            )->references(['provider', 'connection_key', 'id'])
                ->on('integration_operation_intents')
                ->restrictOnDelete();
            $table->foreign(
                ['provider', 'connection_key', 'intent_id', 'operation_type', 'resource_type', 'semantic_slot'],
                'io_operations_intent_identity_fk',
            )->references(['provider', 'connection_key', 'id', 'operation_type', 'resource_type', 'semantic_slot'])
                ->on('integration_operation_intents')
                ->restrictOnDelete();
            $table->foreign(
                ['provider', 'connection_key', 'supersedes_operation_id'],
                'io_operations_scope_supersedes_fk',
            )->references(['provider', 'connection_key', 'id'])
                ->on('integration_operations')
                ->restrictOnDelete();
            $table->unique(['intent_id', 'intent_generation'], 'io_operations_intent_generation_unique');
            $table->index(
                ['provider', 'connection_key', 'intent_id', 'intent_generation'],
                'io_operations_scope_intent_generation_idx',
            );
            $table->index(
                ['provider', 'connection_key', 'supersedes_operation_id'],
                'io_operations_scope_supersedes_idx',
            );
        });

        Schema::table('integration_operation_lookup_keys', function (Blueprint $table): void {
            $table->foreign(
                ['provider', 'connection_key', 'operation_id'],
                'io_lookup_scope_operation_fk',
            )->references(['provider', 'connection_key', 'id'])
                ->on('integration_operations')
                ->restrictOnDelete();
        });

        $this->addPostgresConstraintsAndIndexes();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        Schema::dropIfExists('integration_operation_lookup_keys');
        Schema::dropIfExists('integration_operations');
        Schema::dropIfExists('integration_operation_intents');

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('DROP FUNCTION IF EXISTS io_guard_lookup_alias_immutable() CASCADE');
            $connection->statement('DROP FUNCTION IF EXISTS io_guard_operation_identity_immutable() CASCADE');
            $connection->statement('DROP FUNCTION IF EXISTS io_guard_intent_identity_immutable() CASCADE');
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

    private function addPostgresConstraintsAndIndexes(): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $statements = [
            <<<'SQL'
                ALTER TABLE integration_operation_intents
                ADD CONSTRAINT io_intents_ulid_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND (current_operation_id IS NULL OR current_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                    AND operation_type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$'
                    AND operation_type LIKE provider || '.%'
                    AND resource_type ~ '^[a-z][a-z0-9_.-]{0,127}$'
                    AND semantic_slot ~ '^[a-z][a-z0-9_.:-]{0,127}$'
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_lookup_keys
                ADD CONSTRAINT io_lookup_ulid_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND subject_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND (intent_id IS NULL OR intent_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND (operation_id IS NULL OR operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operations
                ADD CONSTRAINT io_operations_ulid_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND intent_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND (supersedes_operation_id IS NULL OR supersedes_operation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND (active_attempt_id IS NULL OR active_attempt_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND (last_attempt_id IS NULL OR last_attempt_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                    AND operation_type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$'
                    AND operation_type LIKE provider || '.%'
                    AND resource_type ~ '^[a-z][a-z0-9_.-]{0,127}$'
                    AND semantic_slot ~ '^[a-z][a-z0-9_.:-]{0,127}$'
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_intents
                ADD CONSTRAINT io_intents_current_generation_operation_check CHECK (
                    hmac_key_version > 0
                    AND (
                        (current_generation = 0 AND current_operation_id IS NULL)
                        OR (current_generation > 0 AND current_operation_id IS NOT NULL)
                    )
                ),
                ADD CONSTRAINT io_intents_local_reference_envelope_check CHECK (
                    (local_type IS NULL
                        AND local_id_key_version IS NULL
                        AND local_id_cipher IS NULL
                        AND local_id_ciphertext IS NULL
                        AND local_id_ciphertext_sha256 IS NULL
                        AND local_reference_hmac IS NULL)
                    OR (local_type IS NOT NULL
                        AND num_nonnulls(local_id_key_version, local_id_cipher, local_id_ciphertext, local_id_ciphertext_sha256, local_reference_hmac) = 5
                        AND local_id_key_version > 0)
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_lookup_keys
                ADD CONSTRAINT io_lookup_type_check CHECK (
                    key_version > 0
                    AND lookup_type IN ('intent', 'local_reference', 'context', 'correlation', 'cohort')
                    AND (
                        (lookup_type IN ('intent', 'local_reference')
                            AND intent_id IS NOT NULL AND operation_id IS NULL AND subject_id = intent_id)
                        OR (lookup_type IN ('context', 'correlation', 'cohort')
                            AND intent_id IS NULL AND operation_id IS NOT NULL AND subject_id = operation_id)
                    )
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_intent_identity_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation intents cannot be deleted' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.provider IS DISTINCT FROM OLD.provider
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.operation_type IS DISTINCT FROM OLD.operation_type
                        OR NEW.resource_type IS DISTINCT FROM OLD.resource_type
                        OR NEW.semantic_slot IS DISTINCT FROM OLD.semantic_slot
                        OR NEW.local_type IS DISTINCT FROM OLD.local_type
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'integration operation intent identity is immutable' USING ERRCODE = '55000';
                    END IF;

                    IF (
                        NEW.local_id_key_version IS DISTINCT FROM OLD.local_id_key_version
                        OR NEW.local_id_cipher IS DISTINCT FROM OLD.local_id_cipher
                        OR NEW.local_id_ciphertext IS DISTINCT FROM OLD.local_id_ciphertext
                        OR NEW.local_id_ciphertext_sha256 IS DISTINCT FROM OLD.local_id_ciphertext_sha256
                        OR NEW.local_reference_hmac IS DISTINCT FROM OLD.local_reference_hmac
                        OR NEW.intent_key_hmac IS DISTINCT FROM OLD.intent_key_hmac
                        OR NEW.hmac_key_version IS DISTINCT FROM OLD.hmac_key_version
                    ) AND current_setting('integration_operations.lookup_rotation', true) IS DISTINCT FROM 'on' THEN
                        RAISE EXCEPTION 'integration operation intent lookup rotation requires the controlled path' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_intents_identity_immutable
                BEFORE UPDATE OR DELETE ON integration_operation_intents
                FOR EACH ROW EXECUTE FUNCTION io_guard_intent_identity_immutable()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_lookup_alias_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation lookup aliases cannot be deleted' USING ERRCODE = '55000';
                    END IF;

                    IF OLD.retired_at IS NOT NULL
                        OR NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.provider IS DISTINCT FROM OLD.provider
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.lookup_type IS DISTINCT FROM OLD.lookup_type
                        OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
                        OR NEW.intent_id IS DISTINCT FROM OLD.intent_id
                        OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                        OR NEW.key_version IS DISTINCT FROM OLD.key_version
                        OR NEW.digest IS DISTINCT FROM OLD.digest
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.retired_at IS NULL THEN
                        RAISE EXCEPTION 'integration operation lookup alias is immutable except for one-way retirement' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_lookup_alias_immutable
                BEFORE UPDATE OR DELETE ON integration_operation_lookup_keys
                FOR EACH ROW EXECUTE FUNCTION io_guard_lookup_alias_immutable()
                SQL,
            <<<'SQL'
                CREATE UNIQUE INDEX io_lookup_active_intent_digest_unique
                ON integration_operation_lookup_keys (key_version, digest)
                WHERE lookup_type = 'intent'
                SQL,
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
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_operation_identity_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation tombstones cannot be deleted by normal runtime' USING ERRCODE = '55000';
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.intent_id IS DISTINCT FROM OLD.intent_id
                        OR NEW.intent_generation IS DISTINCT FROM OLD.intent_generation
                        OR NEW.supersedes_operation_id IS DISTINCT FROM OLD.supersedes_operation_id
                        OR NEW.provider IS DISTINCT FROM OLD.provider
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.operation_type IS DISTINCT FROM OLD.operation_type
                        OR NEW.resource_type IS DISTINCT FROM OLD.resource_type
                        OR NEW.semantic_slot IS DISTINCT FROM OLD.semantic_slot
                        OR NEW.intent_key_hmac IS DISTINCT FROM OLD.intent_key_hmac
                        OR NEW.payload_schema_version IS DISTINCT FROM OLD.payload_schema_version
                        OR NEW.handler_version IS DISTINCT FROM OLD.handler_version
                        OR NEW.result_schema_version IS DISTINCT FROM OLD.result_schema_version
                        OR NEW.max_remote_writes IS DISTINCT FROM OLD.max_remote_writes
                        OR NEW.priority IS DISTINCT FROM OLD.priority
                        OR NEW.writer_generation IS DISTINCT FROM OLD.writer_generation
                        OR NEW.owner_mode_at_accept IS DISTINCT FROM OLD.owner_mode_at_accept
                        OR NEW.cohort_key_hmac IS DISTINCT FROM OLD.cohort_key_hmac
                        OR NEW.owner_hmac_key_version IS DISTINCT FROM OLD.owner_hmac_key_version
                        OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'integration operation identity, definition, and accepted writer fence are immutable' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_operations_identity_immutable
                BEFORE UPDATE OR DELETE ON integration_operations
                FOR EACH ROW EXECUTE FUNCTION io_guard_operation_identity_immutable()
                SQL,
            <<<'SQL'
                CREATE INDEX io_operations_dispatch_due_idx
                ON integration_operations (provider, connection_key, priority DESC, next_attempt_at, id)
                WHERE status IN ('pending', 'retry_wait')
                SQL,
            <<<'SQL'
                CREATE INDEX io_operations_reconcile_due_idx
                ON integration_operations (provider, connection_key, next_attempt_at, priority DESC, id)
                WHERE status = 'uncertain'
                SQL,
            <<<'SQL'
                CREATE INDEX io_operations_expired_lease_idx
                ON integration_operations (provider, connection_key, lease_expires_at, id)
                WHERE status IN ('processing', 'reconciling')
                SQL,
            <<<'SQL'
                CREATE INDEX io_operations_terminal_tombstone_idx
                ON integration_operations (provider, connection_key, tombstone_after_at, id)
                WHERE status IN ('succeeded', 'failed', 'cancelled')
                SQL,
        ];

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }
    }
};
