<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_operation_writer_fences', function (Blueprint $table): void {
            $table->string('provider', 64);
            $table->string('connection_key', 128);
            $table->string('operation_type', 191);
            $table->unsignedInteger('generation');
            $table->string('owner_mode', 32);
            $table->boolean('cohort_bound');
            $table->unsignedBigInteger('epoch')->default(1);
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('updated_at', precision: 6);

            $table->primary(
                ['provider', 'connection_key', 'operation_type'],
                'io_writer_fences_scope_type_primary',
            );
            $table->index(
                ['provider', 'connection_key', 'generation'],
                'io_writer_fences_scope_generation_idx',
            );
        });

        Schema::create('integration_operation_writer_fence_aliases', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('provider', 64);
            $table->string('connection_key', 128);
            $table->string('operation_type', 191);
            $table->unsignedInteger('generation');
            $table->unsignedSmallInteger('key_version');
            $table->char('digest', 64);
            $table->timestampTz('created_at', precision: 6);
            $table->timestampTz('retired_at', precision: 6)->nullable();

            $table->foreign(
                ['provider', 'connection_key', 'operation_type'],
                'io_writer_fence_aliases_authority_fk',
            )->references(['provider', 'connection_key', 'operation_type'])
                ->on('integration_operation_writer_fences')
                ->restrictOnDelete();
            $table->unique(
                ['provider', 'connection_key', 'operation_type', 'generation', 'key_version'],
                'io_writer_fence_aliases_generation_key_unique',
            );
            $table->index(
                ['provider', 'connection_key', 'operation_type', 'generation', 'retired_at'],
                'io_writer_fence_aliases_current_idx',
            );
        });

        $this->addPostgresConstraintsAndGuards();
    }

    public function down(): void
    {
        $connection = Schema::getConnection();

        Schema::dropIfExists('integration_operation_writer_fence_aliases');
        Schema::dropIfExists('integration_operation_writer_fences');

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'io_assert_operation_writer_fence_authorized',
            'io_assert_writer_fence_alias_generation',
            'io_assert_writer_fence_cohort_alias',
            'io_assert_writer_fence_cutover_committed',
            'io_guard_writer_fence_alias_immutable',
            'io_guard_writer_fence_cutover',
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
                ALTER TABLE integration_operation_writer_fences
                ADD CONSTRAINT io_writer_fences_identity_state_check CHECK (
                    provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                    AND operation_type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$'
                    AND operation_type LIKE provider || '.%'
                    AND generation > 0
                    AND epoch > 0
                    AND owner_mode IN ('off', 'shadow_read', 'canary_write', 'on')
                    AND (owner_mode <> 'canary_write' OR cohort_bound)
                )
                SQL,
            <<<'SQL'
                ALTER TABLE integration_operation_writer_fence_aliases
                ADD CONSTRAINT io_writer_fence_aliases_shape_check CHECK (
                    id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND provider ~ '^[a-z][a-z0-9_]{1,63}$'
                    AND connection_key ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'
                    AND operation_type ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$'
                    AND operation_type LIKE provider || '.%'
                    AND generation > 0
                    AND key_version > 0
                    AND digest ~ '^[a-f0-9]{64}$'
                )
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_writer_fence_cutover() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation writer-fence authority cannot be deleted' USING ERRCODE = '55000';
                    END IF;

                    IF TG_OP = 'INSERT' THEN
                        IF EXISTS (
                            SELECT 1
                            FROM integration_operations
                            WHERE provider = NEW.provider
                                AND connection_key = NEW.connection_key
                                AND operation_type = NEW.operation_type
                                AND (
                                    writer_generation <> NEW.generation
                                    OR owner_mode_at_accept <> NEW.owner_mode
                                    OR (cohort_key_hmac IS NOT NULL) <> NEW.cohort_bound
                                    OR owner_hmac_key_version IS NULL <> (cohort_key_hmac IS NULL)
                                    OR completed_at IS NULL AND (
                                        lease_owner IS NOT NULL
                                        OR active_attempt_id IS NOT NULL
                                        OR request_started_at IS NOT NULL
                                        OR effect_state <> 'not_started'
                                    )
                                )
                        ) THEN
                            RAISE EXCEPTION 'integration operation writer-fence authority bootstrap is unsafe' USING ERRCODE = '55000';
                        END IF;

                        RETURN NEW;
                    END IF;

                    IF NEW.provider IS DISTINCT FROM OLD.provider
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.operation_type IS DISTINCT FROM OLD.operation_type
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.generation <> OLD.generation + 1
                        OR NEW.epoch <> OLD.epoch + 1
                        OR NEW.updated_at <= OLD.updated_at THEN
                        RAISE EXCEPTION 'integration operation writer-fence cutover must advance exactly once' USING ERRCODE = '55000';
                    END IF;

                    IF EXISTS (
                        SELECT 1
                        FROM integration_operations
                        WHERE provider = OLD.provider
                            AND connection_key = OLD.connection_key
                            AND operation_type = OLD.operation_type
                            AND writer_generation = OLD.generation
                            AND completed_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'integration operation writer-fence cutover is blocked by old in-flight work' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_writer_fences_cutover_once
                BEFORE INSERT OR UPDATE OR DELETE ON integration_operation_writer_fences
                FOR EACH ROW EXECUTE FUNCTION io_guard_writer_fence_cutover()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_writer_fence_cutover_committed() RETURNS trigger AS $$
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM integration_operations
                        WHERE provider = OLD.provider
                            AND connection_key = OLD.connection_key
                            AND operation_type = OLD.operation_type
                            AND writer_generation = OLD.generation
                            AND completed_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'integration operation writer-fence cutover committed with old nonterminal work' USING ERRCODE = '23514';
                    END IF;

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_writer_fences_cutover_committed
                AFTER UPDATE ON integration_operation_writer_fences
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_writer_fence_cutover_committed()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_guard_writer_fence_alias_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'integration operation writer-fence aliases cannot be deleted' USING ERRCODE = '55000';
                    END IF;

                    IF OLD.retired_at IS NOT NULL
                        OR NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.provider IS DISTINCT FROM OLD.provider
                        OR NEW.connection_key IS DISTINCT FROM OLD.connection_key
                        OR NEW.operation_type IS DISTINCT FROM OLD.operation_type
                        OR NEW.generation IS DISTINCT FROM OLD.generation
                        OR NEW.key_version IS DISTINCT FROM OLD.key_version
                        OR NEW.digest IS DISTINCT FROM OLD.digest
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.retired_at IS NULL THEN
                        RAISE EXCEPTION 'integration operation writer-fence alias is immutable except for one-way retirement' USING ERRCODE = '55000';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE TRIGGER io_writer_fence_aliases_immutable
                BEFORE UPDATE OR DELETE ON integration_operation_writer_fence_aliases
                FOR EACH ROW EXECUTE FUNCTION io_guard_writer_fence_alias_immutable()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_writer_fence_cohort_alias() RETURNS trigger AS $$
                BEGIN
                    IF NEW.cohort_bound IS DISTINCT FROM EXISTS (
                        SELECT 1
                        FROM integration_operation_writer_fence_aliases
                        WHERE provider = NEW.provider
                            AND connection_key = NEW.connection_key
                            AND operation_type = NEW.operation_type
                            AND generation = NEW.generation
                            AND retired_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'integration operation writer-fence cohort aliases are inconsistent' USING ERRCODE = '23514';
                    END IF;

                    IF EXISTS (
                        SELECT 1
                        FROM integration_operations AS operation
                        WHERE operation.provider = NEW.provider
                            AND operation.connection_key = NEW.connection_key
                            AND operation.operation_type = NEW.operation_type
                            AND operation.writer_generation = NEW.generation
                            AND (
                                (NOT NEW.cohort_bound AND (
                                    operation.cohort_key_hmac IS NOT NULL
                                    OR operation.owner_hmac_key_version IS NOT NULL
                                ))
                                OR (NEW.cohort_bound AND NOT EXISTS (
                                    SELECT 1
                                    FROM integration_operation_writer_fence_aliases AS alias
                                    WHERE alias.provider = NEW.provider
                                        AND alias.connection_key = NEW.connection_key
                                        AND alias.operation_type = NEW.operation_type
                                        AND alias.generation = NEW.generation
                                        AND alias.key_version = operation.owner_hmac_key_version
                                        AND alias.digest = operation.cohort_key_hmac
                                        AND alias.retired_at IS NULL
                                ))
                            )
                    ) THEN
                        RAISE EXCEPTION 'integration operation accepted writer-fence aliases are inconsistent' USING ERRCODE = '23514';
                    END IF;

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_writer_fences_cohort_alias_coherent
                AFTER INSERT OR UPDATE ON integration_operation_writer_fences
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_writer_fence_cohort_alias()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_writer_fence_alias_generation() RETURNS trigger AS $$
                DECLARE
                    authority_generation integer;
                    authority_cohort_bound boolean;
                BEGIN
                    SELECT generation, cohort_bound
                    INTO authority_generation, authority_cohort_bound
                    FROM integration_operation_writer_fences
                    WHERE provider = NEW.provider
                        AND connection_key = NEW.connection_key
                        AND operation_type = NEW.operation_type;

                    IF NOT FOUND
                        OR NEW.generation > authority_generation
                        OR (NEW.generation = authority_generation AND NOT authority_cohort_bound)
                        OR (
                            NEW.generation = authority_generation
                            AND authority_cohort_bound
                            AND NOT EXISTS (
                                SELECT 1
                                FROM integration_operation_writer_fence_aliases
                                WHERE provider = NEW.provider
                                    AND connection_key = NEW.connection_key
                                    AND operation_type = NEW.operation_type
                                    AND generation = NEW.generation
                                    AND retired_at IS NULL
                            )
                        ) THEN
                        RAISE EXCEPTION 'integration operation writer-fence alias generation is invalid' USING ERRCODE = '23514';
                    END IF;

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_writer_fence_aliases_generation_coherent
                AFTER INSERT OR UPDATE ON integration_operation_writer_fence_aliases
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_writer_fence_alias_generation()
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION io_assert_operation_writer_fence_authorized() RETURNS trigger AS $$
                DECLARE
                    authority_row integration_operation_writer_fences%ROWTYPE;
                BEGIN
                    SELECT * INTO authority_row
                    FROM integration_operation_writer_fences
                    WHERE provider = NEW.provider
                        AND connection_key = NEW.connection_key
                        AND operation_type = NEW.operation_type
                    FOR UPDATE;

                    IF NOT FOUND
                        OR authority_row.generation <> NEW.writer_generation
                        OR authority_row.owner_mode <> NEW.owner_mode_at_accept
                        OR authority_row.cohort_bound IS DISTINCT FROM (NEW.cohort_key_hmac IS NOT NULL)
                        OR (NEW.cohort_key_hmac IS NULL) IS DISTINCT FROM (NEW.owner_hmac_key_version IS NULL)
                        OR (
                            authority_row.cohort_bound
                            AND NOT EXISTS (
                                SELECT 1
                                FROM integration_operation_writer_fence_aliases
                                WHERE provider = NEW.provider
                                    AND connection_key = NEW.connection_key
                                    AND operation_type = NEW.operation_type
                                    AND generation = NEW.writer_generation
                                    AND key_version = NEW.owner_hmac_key_version
                                    AND digest = NEW.cohort_key_hmac
                                    AND retired_at IS NULL
                            )
                        ) THEN
                        RAISE EXCEPTION 'integration operation accepted writer fence is not authoritative' USING ERRCODE = '23514';
                    END IF;

                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_operations_writer_fence_authorized_insert
                AFTER INSERT ON integration_operations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_operation_writer_fence_authorized()
                SQL,
            <<<'SQL'
                CREATE CONSTRAINT TRIGGER io_operations_writer_fence_authorized_update
                AFTER UPDATE OF writer_generation, owner_mode_at_accept, cohort_key_hmac, owner_hmac_key_version
                ON integration_operations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION io_assert_operation_writer_fence_authorized()
                SQL,
        ];

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }
    }
};
