<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The link from a ledger entry back to the document that caused it.
 *
 * Until now a journal entry stood alone. That was right while the only entries were journal
 * vouchers, opening balances and the year-end close — all of them entered directly, none of them
 * *caused* by anything else. Sales invoices break that: an invoice is the document, and the entry is
 * its consequence. Without a link, three things are impossible.
 *
 *   1. Tracing. An accountant looking at a receivable line cannot get from it to the invoice.
 *   2. Duplicate protection. Nothing stops the same invoice being posted twice, and the second
 *      posting silently doubles the customer's balance and the period's revenue.
 *   3. Reconciliation. A sub-ledger cannot be proved against its control account if the two are not
 *      joined by anything.
 *
 * A polymorphic pair rather than a nullable `sales_invoice_id`: the same problem arrives again with
 * supplier bills, receipts and payments, and a column per document type would leave `journal_entries`
 * carrying a dozen mostly-null foreign keys. `source_type` holds a morph *alias*, never a class
 * name — the map is enforced, so a namespace refactor cannot orphan a row.
 *
 * WHY THE UNIQUE INDEX EXCLUDES REVERSALS
 * ---------------------------------------
 * A cancelled invoice has two entries against it: the original posting and its mirror. Both should
 * name the invoice, or the reversal becomes untraceable. So uniqueness is asserted only over
 * entries that are not themselves reversals — at most one *originating* entry per document, with
 * however many reversals that entry's own history requires.
 *
 * This is the guard that actually holds. A service-level check on the invoice's status is racy: two
 * concurrent requests both read `draft` and both proceed. The index is what makes the second one
 * fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            // A morph alias, not a class name. 64 characters is generous for the aliases in use
            // (`sales_invoice` is the longest planned) and stops the column becoming a place someone
            // stores a fully-qualified name.
            $table->string('source_type', 64)->nullable()->after('document_type');
            $table->uuid('source_id')->nullable()->after('source_type');
        });

        // Both or neither. A half-set pair is a row that cannot be resolved and cannot be indexed
        // meaningfully, and it is the shape a partially-applied bug produces.
        DB::statement(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_source_columns_check
                CHECK ((source_type IS NULL) = (source_id IS NULL))
        SQL);

        // Leading with `tenant_id` follows the platform's index convention and matches the RLS
        // predicate, so the planner gets a selective first column. Correctness does not depend on it:
        // `source_id` is a UUID and unique on its own.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX journal_entries_source_document_unique
                ON journal_entries (tenant_id, source_type, source_id)
                WHERE source_id IS NOT NULL AND reverses_entry_id IS NULL
        SQL);

        /*
         * The immutability trigger, extended.
         *
         * The original function lists every column by name, and its own comment says why: a column
         * added later and forgotten here would become silently mutable on a posted entry. These two
         * are exactly that case. A posted entry whose `source_id` could be repointed would let a
         * ledger entry be reattributed to a different invoice after the fact, which is precisely the
         * kind of edit the append-only design exists to make impossible.
         *
         * Replaced in full rather than patched, because `CREATE OR REPLACE FUNCTION` is the only way
         * to change it, and the trigger itself is left alone — it references the function by name.
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
                    OR NEW.source_type IS DISTINCT FROM OLD.source_type
                    OR NEW.source_id IS DISTINCT FROM OLD.source_id
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

        DB::statement("COMMENT ON COLUMN journal_entries.source_type IS 'Morph alias of the document that caused this entry. NULL for entries made directly.'");
        DB::statement("COMMENT ON COLUMN journal_entries.source_id IS 'The causing document. Unique across non-reversing entries, which is what stops a document posting twice.'");
    }

    public function down(): void
    {
        // The function is restored to its pre-migration text, so rolling back does not leave a
        // trigger referring to columns that no longer exist.
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

        DB::statement('DROP INDEX IF EXISTS journal_entries_source_document_unique');
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_columns_check');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
