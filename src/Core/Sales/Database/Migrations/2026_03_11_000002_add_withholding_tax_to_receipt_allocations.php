<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-allocation withholding tax on receipt allocations — ADR 0017 §B, Stage 2.
 *
 * Two columns on the existing `receipt_allocations` table, not a side table: WHT posts once, as a line inside
 * the receipt's own single entry, with no second posting and no consumption lifecycle this wave — so none of
 * ADR 0016's table-forcing constraints apply. Columns win on every axis, and — decisively — the freeze comes
 * for free (below).
 *
 *   - `wht_amount numeric(19,4) NOT NULL DEFAULT 0` — the tax withheld against *this* allocation; `0` means "no
 *     WHT". `NOT NULL DEFAULT 0` (not nullable) so `Σ wht` is a plain sum with no NULL-vs-0 ambiguity, every
 *     existing row is backfilled to `0` by the DEFAULT, and the regression path is `Σ wht = 0` for all
 *     historical and all non-WHT receipts.
 *   - `wht_certificate_reference varchar(120) NULL` — the customer's certificate/document reference, evidence
 *     for a later claim only; posts nothing. INDEPENDENT of `wht_amount` (Gate-2 fork (a)): no cross-field
 *     CHECK, a certificate may be recorded before or after the amount is finalised.
 *
 * TWO SAME-ROW CHECKS
 * -------------------
 * Both operands live on the same row, so — unlike "allocation ≤ invoice amount_due", which joins to
 * `sales_invoices` and cannot be a CHECK — these are enforceable at the database as backstops under the service:
 *
 *   - `receipt_allocations_wht_non_negative_check CHECK (wht_amount >= 0)` — the backstop for Gate-1 #2's ≥ 0.
 *   - `receipt_allocations_wht_not_exceeding_amount_check CHECK (wht_amount <= amount)` — the backstop for
 *     "WHT ≤ the gross AR it is withheld against", the direct same-row analogue of
 *     `sales_invoices_amount_paid_not_exceeding_total_check`.
 *
 * NO IMMUTABILITY-TRIGGER CHANGE
 * ------------------------------
 * `asids_receipt_allocations_immutable()` refuses EVERY UPDATE and DELETE unconditionally — it enumerates no
 * columns — so the two new columns are frozen the instant the allocation is written, with no edit to the trigger
 * and no omission risk. RLS is unchanged (same table, same tenant key, same policy — RLS is per-table and no
 * table is added).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_allocations', function (Blueprint $table): void {
            // NOT NULL DEFAULT 0: every historical row is backfilled to 0 (their receipts carried no WHT), and
            // `Σ wht` is a plain sum with no NULL-vs-0 ambiguity.
            $table->decimal('wht_amount', 19, 4)->default(0)->after('amount');

            // A free optional string, traceability only; matches `customer_receipts.reference varchar(120)`.
            $table->string('wht_certificate_reference', 120)->nullable()->after('wht_amount');
        });

        // Same-row backstops under the service refusals (§D). Both operands live on this row, so — unlike the
        // cross-table allocation rules — these are genuine CHECKs.
        DB::statement(<<<'SQL'
            ALTER TABLE receipt_allocations
                ADD CONSTRAINT receipt_allocations_wht_non_negative_check
                CHECK (wht_amount >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_allocations
                ADD CONSTRAINT receipt_allocations_wht_not_exceeding_amount_check
                CHECK (wht_amount <= amount)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE receipt_allocations DROP CONSTRAINT IF EXISTS receipt_allocations_wht_not_exceeding_amount_check');
        DB::statement('ALTER TABLE receipt_allocations DROP CONSTRAINT IF EXISTS receipt_allocations_wht_non_negative_check');

        Schema::table('receipt_allocations', function (Blueprint $table): void {
            $table->dropColumn(['wht_amount', 'wht_certificate_reference']);
        });
    }
};
