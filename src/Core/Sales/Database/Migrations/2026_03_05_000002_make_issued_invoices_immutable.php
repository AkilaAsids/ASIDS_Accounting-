<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An issued invoice is a historical fact.
 *
 * Milestone 4 prepared the boundary with CHECK constraints that made every partial version of the issuing
 * transition unrepresentable. This is the other half: once an invoice leaves `draft`, its contents cannot be
 * edited at all. The customer holds a copy, the ledger holds a posting derived from these figures, and a return
 * may already report them — so an edit does not correct the document, it makes three records disagree.
 *
 * WHAT REMAINS MUTABLE, AND WHY ONLY THAT
 * ---------------------------------------
 * Three columns, plus `updated_at`:
 *
 *   - `status`, for the transitions that are real events rather than corrections: issued → cancelled now,
 *     issued → partially_paid → paid when payments arrive.
 *   - `amount_paid` and `amount_due`, which Phase 4 maintains as money is received. They ship held at zero by
 *     a phase-scoped CHECK, so nothing can move them yet; permitting them here means Phase 4 adds behaviour
 *     rather than a migration that loosens a trigger.
 *
 * Everything else is frozen, including `reference`, `notes` and `terms`. Those carry no accounting weight, and
 * freezing them costs a company the ability to fix a typo after issuing — which is the right trade: they appear
 * on the document the customer received, and a stored copy that differs from the one they hold is worse than a
 * typo. `journal_entries` treats its own `reference` the same way.
 *
 * TWO REFUSALS BEYOND COLUMN IMMUTABILITY
 * ---------------------------------------
 * Un-issuing is refused outright — `draft` is unreachable once left. A number has been consumed, a ledger entry
 * exists, and returning to draft would strand both.
 *
 * A cancelled invoice is final. Its posting has already been reversed, so a further transition would either
 * double-reverse or resurrect a document whose reversal is already in the books. `asids_journal_entries_immutable`
 * refuses changes to an already-reversed entry for the same reason.
 *
 * The column list is written out by name rather than as `OLD IS DISTINCT FROM NEW`, matching the journal trigger:
 * a column added later and forgotten here would become silently mutable on an issued invoice, and that is the
 * kind of gap an auditor finds rather than a test.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_sales_invoices_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Invoice % has been issued and cannot be deleted. Cancel it instead, which reverses its posting and leaves both entries in the ledger.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (OLD.status = 'cancelled') THEN
                    RAISE EXCEPTION 'Invoice % is cancelled and cannot be changed further. Its posting has already been reversed.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.status = 'draft') THEN
                    RAISE EXCEPTION 'Invoice % cannot return to draft. It has consumed a number and posted to the ledger.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.reference IS DISTINCT FROM OLD.reference
                    OR NEW.invoice_date IS DISTINCT FROM OLD.invoice_date
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.discount_total IS DISTINCT FROM OLD.discount_total
                    OR NEW.tax_total IS DISTINCT FROM OLD.tax_total
                    OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.issued_at IS DISTINCT FROM OLD.issued_at
                    OR NEW.issued_by_id IS DISTINCT FROM OLD.issued_by_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.terms IS DISTINCT FROM OLD.terms
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Invoice % has been issued; only its status and payment figures may change. Cancel and reissue to correct anything else.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // `WHEN (OLD.status <> 'draft')` is what lets the issuing transition itself through: at that moment the
        // row is still a draft, so the trigger does not fire, and one UPDATE may set status, number, issued_at,
        // issued_by_id and journal_entry_id together. Every later update is caught.
        DB::statement(<<<'SQL'
            CREATE TRIGGER sales_invoices_immutable
                BEFORE UPDATE OR DELETE ON sales_invoices
                FOR EACH ROW
                WHEN (OLD.status <> 'draft')
                EXECUTE FUNCTION asids_sales_invoices_immutable()
        SQL);

        /*
         * Lines are frozen by their parent's status rather than by their own, because they have none.
         *
         * A separate trigger is needed regardless: cascading from the header would only cover deletes, and the
         * risk here is an UPDATE that quietly changes a quantity or an amount on a document already posted.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_sales_invoice_lines_immutable() RETURNS trigger AS $$
            DECLARE
                parent_status text;
            BEGIN
                SELECT status INTO parent_status
                FROM sales_invoices
                WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.sales_invoice_id ELSE NEW.sales_invoice_id END;

                -- Null when the header is going away in the same statement, which is the legitimate cascade
                -- from deleting a draft. Nothing to protect in that case.
                IF (parent_status IS NULL OR parent_status = 'draft') THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                RAISE EXCEPTION 'The lines of an issued invoice cannot be changed. Cancel and reissue to correct them.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER sales_invoice_lines_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON sales_invoice_lines
                FOR EACH ROW
                EXECUTE FUNCTION asids_sales_invoice_lines_immutable()
        SQL);

        DB::statement("COMMENT ON TABLE sales_invoices IS 'Sales invoice headers. Drafts are freely editable and hard-deletable; an issued invoice is immutable apart from its status and payment figures. See ADR 0007.'");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sales_invoice_lines_immutable ON sales_invoice_lines');
        DB::statement('DROP FUNCTION IF EXISTS asids_sales_invoice_lines_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS sales_invoices_immutable ON sales_invoices');
        DB::statement('DROP FUNCTION IF EXISTS asids_sales_invoices_immutable()');

        DB::statement("COMMENT ON TABLE sales_invoices IS 'Sales invoice headers. Drafts are hard-deletable; issued invoices are statutory records and cannot be removed. See ADR 0007.'");
    }
};
