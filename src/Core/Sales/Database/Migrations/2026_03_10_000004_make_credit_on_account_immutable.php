<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Immutability for the held-credit tables — ADR 0016 §B.
 *
 * Two different shapes, because the two tables have different lives:
 *
 *   - `receipt_held_credits` is a *balance*. Its identity and its original amount are history the moment the
 *     row exists, but its `applied_amount`, `remaining_amount` and `status` move as credit is consumed or the
 *     receipt is cancelled. So it gets a **conditional** trigger in the ADR 0015 shape: a frozen-column list
 *     that refuses any change to id, tenant/company/customer, the source receipt, the currency, the original
 *     amount, or the audit columns — leaving exactly `applied_amount`, `remaining_amount`, `status` and
 *     `updated_at` writable, the analogue of the invoice trigger leaving `amount_paid`/`amount_due`/`status`
 *     writable. DELETE is refused outright: a credit record is unwound by delta, never removed.
 *
 *   - `credit_applications` is an *event*. It is a historical fact the moment it exists; reversing an
 *     application is a new posting, never an edit (and is out of scope this wave). So it gets an
 *     **unconditional full freeze**, byte-for-byte the shape of `asids_receipt_allocations_immutable()`:
 *     every UPDATE and DELETE is refused, INSERT alone left alone so the apply transaction can write the row.
 *
 * The frozen-column list is written out by name rather than as `OLD IS DISTINCT FROM NEW`, matching the
 * receipt, invoice and journal triggers: a column added later and forgotten here would become silently mutable
 * on a held-credit balance, the kind of gap an auditor finds rather than a test.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Conditional freeze on the balance. `applied_amount`, `remaining_amount`, `status` and `updated_at`
        // are the only columns apply and cancel need to move; everything else is frozen from insert.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_receipt_held_credits_immutable() RETURNS trigger AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    RAISE EXCEPTION 'A held-credit record is unwound by delta, never deleted. It is the balance of an overpayment held against the customer.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.company_id IS DISTINCT FROM OLD.company_id
                    OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.customer_receipt_id IS DISTINCT FROM OLD.customer_receipt_id
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.original_amount IS DISTINCT FROM OLD.original_amount
                    OR NEW.created_by_id IS DISTINCT FROM OLD.created_by_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'A held-credit record is frozen apart from its running balance. Only applied_amount, remaining_amount and status may change, as credit is applied or the receipt is cancelled.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER receipt_held_credits_immutable
                BEFORE UPDATE OR DELETE ON receipt_held_credits
                FOR EACH ROW
                EXECUTE FUNCTION asids_receipt_held_credits_immutable()
        SQL);

        // Unconditional full freeze on the event, the shape of the receipt allocations trigger. INSERT is left
        // alone so the apply transaction can write the row; every later UPDATE or DELETE is refused.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_credit_applications_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'A credit application is immutable once written. It records how a held credit was reclassified onto an invoice, which cannot change; reverse it with a new posting instead.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER credit_applications_immutable
                BEFORE UPDATE OR DELETE ON credit_applications
                FOR EACH ROW
                EXECUTE FUNCTION asids_credit_applications_immutable()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS credit_applications_immutable ON credit_applications');
        DB::statement('DROP FUNCTION IF EXISTS asids_credit_applications_immutable()');
        DB::statement('DROP TRIGGER IF EXISTS receipt_held_credits_immutable ON receipt_held_credits');
        DB::statement('DROP FUNCTION IF EXISTS asids_receipt_held_credits_immutable()');
    }
};
