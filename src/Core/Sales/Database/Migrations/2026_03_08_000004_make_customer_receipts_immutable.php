<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A posted receipt is a historical fact.
 *
 * Modelled on `asids_sales_invoices_immutable()`. Because receipts are posted-only this wave (Gate-1 #1,
 * cancellation deferred), the trigger freezes them outright: there is no draft state and no permitted
 * transition yet. It refuses DELETE and any UPDATE that changes a money, account, customer, number, date or
 * ledger-link column.
 *
 * THE BOUNDARY IS PREPARED, NOT POPULATED
 * ---------------------------------------
 * `status` and `updated_at` are the two columns left mutable, exactly as the invoice trigger leaves `status`
 * and the payment figures mutable ahead of the phase that uses them. That is what lets the deferred reversal
 * sub-slice add a posted → cancelled transition the way Milestone 5's Stage 4 added issued → cancelled —
 * without loosening a trigger that has already shipped. Nothing this wave writes a receipt after its insert,
 * so the freeze has no legitimate update to permit yet.
 *
 * The column list is written out by name rather than as `OLD IS DISTINCT FROM NEW`, matching the invoice and
 * journal triggers: a column added later and forgotten here would become silently mutable on a posted receipt,
 * which is the kind of gap an auditor finds rather than a test.
 *
 * `receipt_allocations` gets its own trigger — frozen once written — because immutability, like RLS, is not
 * transitive, the same reason `sales_invoice_lines` has its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_customer_receipts_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'Receipt % has been posted and cannot be deleted. A posted receipt is a statutory record.', OLD.number
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
                    OR NEW.receipt_date IS DISTINCT FROM OLD.receipt_date
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.payment_method IS DISTINCT FROM OLD.payment_method
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                    OR NEW.posted_at IS DISTINCT FROM OLD.posted_at
                    OR NEW.posted_by_id IS DISTINCT FROM OLD.posted_by_id
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'Receipt % has been posted; it is immutable. Reverse it with a cancellation once that lands, never an edit.', OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // No `WHEN` guard: a receipt is posted from insert, so every UPDATE and DELETE is caught. The insert
        // itself is not a trigger event here, which is how the whole posted row is written in one statement.
        DB::statement(<<<'SQL'
            CREATE TRIGGER customer_receipts_immutable
                BEFORE UPDATE OR DELETE ON customer_receipts
                FOR EACH ROW
                EXECUTE FUNCTION asids_customer_receipts_immutable()
        SQL);

        /*
         * Allocations are frozen once written. Unlike the invoice lines' trigger — which reads its parent's
         * status because a draft invoice's lines are still editable — a receipt has no draft state, so an
         * allocation is frozen the moment it exists. INSERT is left alone so the receipt's own transaction can
         * write its lines; every later UPDATE or DELETE is refused.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_receipt_allocations_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A receipt allocation is immutable once written. It records how a posted receipt was applied, which cannot change.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER receipt_allocations_immutable
                BEFORE UPDATE OR DELETE ON receipt_allocations
                FOR EACH ROW
                EXECUTE FUNCTION asids_receipt_allocations_immutable()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS receipt_allocations_immutable ON receipt_allocations');
        DB::statement('DROP FUNCTION IF EXISTS asids_receipt_allocations_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS customer_receipts_immutable ON customer_receipts');
        DB::statement('DROP FUNCTION IF EXISTS asids_customer_receipts_immutable()');
    }
};
