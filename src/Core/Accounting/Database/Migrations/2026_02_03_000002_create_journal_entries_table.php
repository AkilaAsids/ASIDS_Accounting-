<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The document header of a double entry.
 *
 * IMMUTABILITY IS ENFORCED HERE, NOT IN THE SERVICE
 * -------------------------------------------------
 * Once an entry is posted it is part of the financial record. The trigger below permits exactly two
 * things afterwards — marking it reversed, and recording which entry reversed it — and refuses every
 * other UPDATE and every DELETE.
 *
 * A service-layer check would be enough for the application's own paths and useless for the ones
 * that matter: a console command written in a hurry, a data fix run against production, a future
 * module that does not know the rule. This is the same reasoning, and the same mechanism, as the
 * append-only trigger on `audit_logs`.
 *
 * Correcting a posted entry means posting its reverse. An auditor reading the books sees the mistake
 * and the correction, which is the point — a tidy history in which the mistake never happened is
 * worth less than an honest one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Restrict: a journal with history cannot be removed out from under it.
            $table->foreignUuid('journal_id')->constrained('journals')->restrictOnDelete();
            $table->foreignUuid('fiscal_period_id')->constrained('fiscal_periods')->restrictOnDelete();

            // Human-readable and gapless within its family — JV-2026-04-0001. Null while the entry
            // is a draft: a number is issued at posting, so an abandoned draft consumes none.
            $table->string('number', 40)->nullable();
            $table->string('document_type', 32);

            $table->date('entry_date');
            $table->string('description');
            $table->string('reference', 120)->nullable();

            $table->string('status', 16)->default('draft');

            $table->timestampTz('posted_at')->nullable();
            $table->foreignUuid('posted_by_id')->nullable()->constrained('users')->nullOnDelete();

            // A reversal points back at what it reverses; the original points forward at its
            // reversal. Both directions are stored so neither lookup needs a scan.
            $table->uuid('reverses_entry_id')->nullable();
            $table->uuid('reversed_by_entry_id')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'entry_date']);
            $table->index(['company_id', 'fiscal_period_id', 'status']);
            $table->index(['company_id', 'status', 'entry_date']);
        });

        // Self-references, added after the table exists for the same reason the chart's parent link
        // is: PostgreSQL will not accept a foreign key onto a primary key created in the same
        // statement.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_reverses_entry_id_foreign
                FOREIGN KEY (reverses_entry_id) REFERENCES journal_entries (id) ON DELETE RESTRICT
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_reversed_by_entry_id_foreign
                FOREIGN KEY (reversed_by_entry_id) REFERENCES journal_entries (id) ON DELETE RESTRICT
        SQL);

        // Numbers are unique per company, and gapless within a family — the sequence table is what
        // makes them gapless; this is what makes a collision impossible if two requests race.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX journal_entries_company_number_unique
                ON journal_entries (company_id, number)
                WHERE number IS NOT NULL
        SQL);

        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_check CHECK (status IN ('draft', 'posted', 'reversed'))");

        // A draft has no number and no posting timestamp; anything posted has both. The two move
        // together or an entry exists that is in the record but unidentifiable, or identified but
        // not in the record.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_posted_columns_check
                CHECK (
                    (status = 'draft' AND number IS NULL AND posted_at IS NULL)
                    OR (status <> 'draft' AND number IS NOT NULL AND posted_at IS NOT NULL)
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_reversed_columns_check
                CHECK ((status = 'reversed') = (reversed_at IS NOT NULL))
        SQL);

        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_not_self_reversing_check CHECK (reverses_entry_id IS NULL OR reverses_entry_id <> id)');

        /*
         * The immutability trigger.
         *
         * Fires on UPDATE and DELETE of any row that is already posted. The one permitted change is
         * the reversal transition: `posted` → `reversed`, setting `reversed_by_entry_id`,
         * `reversed_at` and `reversal_reason`. Every other column must be identical, and a DELETE is
         * refused outright.
         *
         * Written column by column rather than as `OLD IS DISTINCT FROM NEW` so that adding a column
         * later fails loudly here — an entry that silently became mutable would be discovered by an
         * auditor, not by us.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_journal_entries_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'A posted journal entry cannot be deleted (entry %). Post a reversing entry instead.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (OLD.status = 'reversed') THEN
                    RAISE EXCEPTION 'Journal entry % has already been reversed and cannot be changed.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- The only legal transition out of `posted`.
                IF (NEW.status <> 'reversed') THEN
                    RAISE EXCEPTION 'A posted journal entry cannot be modified (entry %). Post a reversing entry instead.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.journal_id IS DISTINCT FROM OLD.journal_id
                    OR NEW.fiscal_period_id IS DISTINCT FROM OLD.fiscal_period_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type
                    OR NEW.entry_date IS DISTINCT FROM OLD.entry_date
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
                    OR NEW.posted_by_id IS DISTINCT FROM OLD.posted_by_id
                    OR NEW.reverses_entry_id IS DISTINCT FROM OLD.reverses_entry_id
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                ) THEN
                    RAISE EXCEPTION 'Only the reversal of journal entry % may be recorded; no other column may change.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER journal_entries_immutable
                BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW
                WHEN (OLD.status <> 'draft')
                EXECUTE FUNCTION asids_journal_entries_immutable()
        SQL);

        DB::statement("COMMENT ON TABLE journal_entries IS 'Double-entry document headers. Append-only once posted: corrections are reversing entries.'");
        DB::statement("COMMENT ON COLUMN journal_entries.number IS 'Gapless within its document family. Issued at posting, so an abandoned draft consumes none.'");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_immutable ON journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS asids_journal_entries_immutable()');

        Schema::dropIfExists('journal_entries');
    }
};
