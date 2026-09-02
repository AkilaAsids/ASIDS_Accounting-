<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A posted bill is a historical fact — the payable-side mirror of `make_issued_invoices_immutable`, as it
 * stood before cancellation.
 *
 * The create migration prepared the posted boundary with CHECK constraints. This is the other half: once a
 * bill leaves `draft`, its contents cannot be edited at all. The supplier holds the original, the ledger
 * holds a posting derived from these figures, and a return may already report them — so an edit does not
 * correct the document, it makes three records disagree.
 *
 * WHAT REMAINS MUTABLE, AND WHY ONLY THAT
 * ---------------------------------------
 * `status` (posted → partially_paid → paid when payments arrive, and cancelled later), plus `amount_paid`
 * and `amount_due` (Wave 8 maintains these; they ship held at zero by a phase-scoped CHECK, so permitting
 * them here means Wave 8 adds behaviour rather than a migration loosening a trigger), plus `updated_at`.
 * Everything else is frozen, including `notes` and `terms`.
 *
 * NO CANCELLATION BRANCH THIS WAVE
 * --------------------------------
 * Sales' trigger refuses further change to an already-cancelled invoice; that branch arrives with the cancel
 * feature. This wave cannot cancel anything, so the function has only the un-post refusal and the frozen
 * column list. The column list is written by name rather than `OLD IS DISTINCT FROM NEW` so a column added
 * later and forgotten does not become silently mutable — the kind of gap an auditor finds rather than a test.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_bills_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Bill % has been posted and cannot be deleted. It is a statutory record.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.status = 'draft') THEN
                    RAISE EXCEPTION 'Bill % cannot return to draft. It has consumed a number and posted to the ledger.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                    OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
                    OR NEW.supplier_invoice_number IS DISTINCT FROM OLD.supplier_invoice_number
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.bill_date IS DISTINCT FROM OLD.bill_date
                    OR NEW.due_date IS DISTINCT FROM OLD.due_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.exchange_rate IS DISTINCT FROM OLD.exchange_rate
                    OR NEW.subtotal IS DISTINCT FROM OLD.subtotal
                    OR NEW.discount_total IS DISTINCT FROM OLD.discount_total
                    OR NEW.tax_total IS DISTINCT FROM OLD.tax_total
                    OR NEW.total IS DISTINCT FROM OLD.total
                    OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
                    OR NEW.posted_by_id IS DISTINCT FROM OLD.posted_by_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.notes IS DISTINCT FROM OLD.notes
                    OR NEW.terms IS DISTINCT FROM OLD.terms
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Bill % has been posted; only its status and payment figures may change.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // `WHEN (OLD.status <> 'draft')` lets the posting transition itself through: at that moment the row is
        // still a draft, so the trigger does not fire, and one UPDATE may set status, number, posted_at,
        // posted_by_id and journal_entry_id together. Every later update is caught.
        DB::statement(<<<'SQL'
            CREATE TRIGGER bills_immutable
                BEFORE UPDATE OR DELETE ON bills
                FOR EACH ROW
                WHEN (OLD.status <> 'draft')
                EXECUTE FUNCTION asids_bills_immutable()
        SQL);

        /*
         * Lines are frozen by their parent's status. A separate trigger is needed regardless: cascading from
         * the header would only cover deletes, and the risk here is an UPDATE or INSERT on a posted document.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_bill_lines_immutable() RETURNS trigger AS $$
            DECLARE
                parent_status text;
            BEGIN
                SELECT status INTO parent_status
                FROM bills
                WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.bill_id ELSE NEW.bill_id END;

                -- Null when the header is going away in the same statement — the legitimate cascade from
                -- deleting a draft. Nothing to protect in that case.
                IF (parent_status IS NULL OR parent_status = 'draft') THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                RAISE EXCEPTION 'The lines of a posted bill cannot be changed.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER bill_lines_immutable
                BEFORE INSERT OR UPDATE OR DELETE ON bill_lines
                FOR EACH ROW
                EXECUTE FUNCTION asids_bill_lines_immutable()
        SQL);

        DB::statement("COMMENT ON TABLE bills IS 'Bill (purchase invoice) headers. Drafts are freely editable and hard-deletable; a posted bill is immutable apart from its status and payment figures. See ADR 0019.'");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS bill_lines_immutable ON bill_lines');
        DB::statement('DROP FUNCTION IF EXISTS asids_bill_lines_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS bills_immutable ON bills');
        DB::statement('DROP FUNCTION IF EXISTS asids_bills_immutable()');

        DB::statement("COMMENT ON TABLE bills IS 'Bill (purchase invoice) headers. Drafts are hard-deletable; posted bills are statutory records and cannot be removed. See ADR 0019.'");
    }
};
