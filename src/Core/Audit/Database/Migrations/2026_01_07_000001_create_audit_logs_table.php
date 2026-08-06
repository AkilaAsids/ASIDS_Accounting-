<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable, tamper-evident audit trail.
 *
 * Three properties make this suitable for a system of financial record:
 *
 *   1. Append only. A database trigger rejects every UPDATE and every DELETE, so
 *      no application bug — and no compromised application credential — can
 *      rewrite history. Retention pruning is the single exception and must
 *      announce itself through a session variable that only the schema owner can
 *      set.
 *
 *   2. Hash chained, out of band. Each sealed row stores the SHA-256 of its own
 *      canonical payload concatenated with the previous sealed row's hash, per tenant,
 *      so removing or altering any row breaks every subsequent link —
 *      `asids:audit-verify` detects it.
 *
 *      The chaining happens in `asids:audit-seal` (every five minutes) rather than at
 *      insert time, and that is a deliberate throughput decision. Computing the chain
 *      inline requires reading the tenant's latest hash under a lock, which serialises
 *      every audited write in the workspace for the duration of the surrounding business
 *      transaction. At ten million transactions that is the platform's ceiling. Writing
 *      unchained and sealing in batches keeps the hot path lock-free while still making
 *      history tamper-evident; the only cost is that the newest few minutes of entries are
 *      unsealed, and the row itself is still written atomically with the change it
 *      describes, so nothing can be lost.
 *
 *   3. Self-describing. Old and new values, the actor, the impersonator (if any),
 *      the request that caused it and the resulting hash are all in one row, so
 *      an auditor never needs to correlate across systems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Globally monotonic insertion order. The hash chain is ordered by
            // this column within a tenant. It is a PostgreSQL identity column
            // rather than a second primary key: `id` stays the surrogate key that
            // application code and API responses use.
            $table->bigInteger('sequence');

            $table->uuid('tenant_id')->nullable();
            $table->uuid('company_id')->nullable();

            // ── What changed ───────────────────────────────────────────────
            $table->string('auditable_type');
            $table->uuid('auditable_id');
            $table->string('event', 32);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('changed_attributes')->nullable();

            // ── Who did it ─────────────────────────────────────────────────
            $table->string('actor_type', 24)->default('user');
            $table->uuid('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            // Set when an ASIDS operator was acting as the user.
            $table->uuid('impersonator_id')->nullable();
            $table->uuid('access_token_id')->nullable();

            // ── In what context ────────────────────────────────────────────
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_method', 10)->nullable();
            $table->text('request_url')->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('channel', 24)->default('web');
            $table->jsonb('tags')->nullable();
            $table->text('reason')->nullable();

            // ── Integrity ──────────────────────────────────────────────────
            // Both nullable, and `sealed_at` with them: entries are written unchained on
            // the hot path and linked afterwards by the sealer. See the header comment.
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
            $table->timestampTz('sealed_at')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // The chain walk, and the per-tenant timeline view.
            $table->index(['tenant_id', 'sequence']);
            $table->index(['tenant_id', 'created_at']);
            // "History of this record" — the most common auditor question.
            $table->index(['auditable_type', 'auditable_id', 'sequence'], 'audit_logs_auditable_index');
            $table->index(['actor_id', 'created_at']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index('request_id');
        });

        // The sealer's work queue: the unsealed tail of one tenant, in order. A partial index
        // keeps it tiny — it holds minutes of entries, not years.
        DB::statement('CREATE INDEX audit_logs_unsealed_index ON audit_logs (tenant_id, sequence) WHERE sealed_at IS NULL');

        // Let PostgreSQL own the sequence so no two writers can pick the same
        // ordinal, and make it unique so a gap or a duplicate is impossible.
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN sequence ADD GENERATED BY DEFAULT AS IDENTITY');
        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_sequence_unique UNIQUE (sequence)');

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ('created', 'updated', 'deleted', 'restored', 'force_deleted', 'viewed', 'exported', 'approved', 'rejected', 'posted', 'voided', 'login', 'logout', 'permission_changed', 'setting_changed', 'impersonation_started', 'impersonation_ended'))");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user', 'system', 'api_token', 'console', 'job'))");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_channel_check CHECK (channel IN ('web', 'api', 'mobile', 'console', 'queue'))");
        // A system actor has no identity; a user actor must have one.
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_identity_check CHECK (actor_type <> 'user' OR actor_id IS NOT NULL)");

        // GIN indexes let an auditor ask "which change touched this field?" and
        // "which entries carry this tag?" without scanning the table.
        DB::statement('CREATE INDEX audit_logs_new_values_gin ON audit_logs USING gin (new_values jsonb_path_ops)');
        DB::statement('CREATE INDEX audit_logs_tags_gin ON audit_logs USING gin (tags jsonb_path_ops)');

        // ── Append-only enforcement ────────────────────────────────────────
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_audit_logs_guard() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    -- Sealing is the one permitted update. It may only ever fill in the chain
                    -- columns of a not-yet-sealed row, and every column that carries meaning
                    -- must be byte-identical. That makes "seal" incapable of rewriting history
                    -- even though it holds UPDATE rights.
                    IF COALESCE(current_setting('asids.audit_seal', true), 'off') = 'on'
                        AND OLD.sealed_at IS NULL
                        AND NEW.sealed_at IS NOT NULL
                        AND NEW.id = OLD.id
                        AND NEW.sequence = OLD.sequence
                        AND NEW.tenant_id IS NOT DISTINCT FROM OLD.tenant_id
                        AND NEW.company_id IS NOT DISTINCT FROM OLD.company_id
                        AND NEW.auditable_type = OLD.auditable_type
                        AND NEW.auditable_id = OLD.auditable_id
                        AND NEW.event = OLD.event
                        AND NEW.old_values IS NOT DISTINCT FROM OLD.old_values
                        AND NEW.new_values IS NOT DISTINCT FROM OLD.new_values
                        AND NEW.changed_attributes IS NOT DISTINCT FROM OLD.changed_attributes
                        AND NEW.actor_type = OLD.actor_type
                        AND NEW.actor_id IS NOT DISTINCT FROM OLD.actor_id
                        AND NEW.impersonator_id IS NOT DISTINCT FROM OLD.impersonator_id
                        AND NEW.created_at = OLD.created_at
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'audit_logs is append-only; UPDATE is not permitted (id=%)', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    -- Retention pruning is the only legitimate deletion, and it must announce
                    -- itself through a session variable.
                    IF COALESCE(current_setting('asids.audit_prune', true), 'off') <> 'on' THEN
                        RAISE EXCEPTION 'audit_logs is append-only; DELETE is not permitted (id=%)', OLD.id
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN OLD;
            END;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_logs_guard
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION asids_audit_logs_guard();
        SQL);

        // TRUNCATE bypasses row triggers entirely, so it is blocked separately.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_audit_logs_truncate_guard() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs may not be truncated'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_logs_truncate_guard
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION asids_audit_logs_truncate_guard();
        SQL);

        DB::statement('COMMENT ON TABLE audit_logs IS \'Append-only, hash-chained audit trail. See asids:audit-verify.\'');
    }

    public function down(): void
    {
        // The guards must go before the table, or the drop itself is refused in
        // a way that is confusing to debug.
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_truncate_guard ON audit_logs');
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_guard ON audit_logs');
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS asids_audit_logs_truncate_guard()');
        DB::statement('DROP FUNCTION IF EXISTS asids_audit_logs_guard()');
    }
};
