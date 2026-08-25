<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The payments phase earns the CHECK Milestone 4 held in escrow.
 *
 * Two changes to `sales_invoices`, both ADR 0014 Stage 1.
 *
 * DROP `sales_invoices_no_payments_until_payments_phase`
 * -----------------------------------------------------
 * The phase-scoped CHECK holding `amount_paid = 0` (`2026_03_04_000001`). Safe for existing data: every
 * currently-issued invoice has `amount_paid = 0` today because that CHECK guaranteed it, so dropping it
 * violates nothing. This is the migration Milestone 4 always meant this phase to ship — the columns arrived
 * early "so this phase adds behaviour rather than a migration."
 *
 * ADD `sales_invoices_amount_paid_not_exceeding_total_check (amount_paid <= total)`
 * --------------------------------------------------------------------------------
 * The database backstop for AC-5.2: two racing receipts cannot together drive an invoice's `amount_paid` past
 * its `total`, regardless of what the service does or fails to do. The existing
 * `sales_invoices_non_negative_check` asserts `amount_paid >= 0` but nothing about the upper bound; this
 * closes it. Combined with the existing `sales_invoices_amount_due_check (amount_due = total - amount_paid)`,
 * `amount_paid <= total` is exactly equivalent to `amount_due >= 0` — so the ledger refuses a negative
 * outstanding balance by construction. Trivially satisfied by every existing row (`0 <= total`, and
 * `total >= 0` already holds).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT sales_invoices_no_payments_until_payments_phase');

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_amount_paid_not_exceeding_total_check
                CHECK (amount_paid <= total)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT sales_invoices_amount_paid_not_exceeding_total_check ON sales_invoices IS 'AC-5.2 backstop: no receipt can drive amount_paid past total. Equivalent to amount_due >= 0 given the amount_due invariant.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_amount_paid_not_exceeding_total_check');

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_no_payments_until_payments_phase
                CHECK (amount_paid = 0)
        SQL);
    }
};
