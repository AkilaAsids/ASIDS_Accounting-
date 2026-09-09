<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The WHT snapshot on a supplier payment header (ADR 0021 §A3, Gate-1 dec. 3, 5, 7).
 *
 * The header snapshots the total withheld and the one rate applied; the per-bill split is NOT stored —
 * `payment_allocations.amount` is the GROSS allocated per bill (dec. 3), so the subledger and the deferred
 * full-allocation trigger (`Σ allocations = amount`) are untouched. The journal entry reconstructs as
 * `Cr WHT Payable = wht_amount`, `Cr Bank = amount − wht_amount`.
 *
 * `wht_amount` defaults to 0, which makes every existing and every non-withholding payment byte-for-byte
 * Wave 8a (AC-1.2). `wht_rate` is NULL when nothing was withheld (fork 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->decimal('wht_amount', 19, 4)->default(0)->after('amount');
            $table->decimal('wht_rate', 5, 2)->nullable()->after('wht_amount');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_wht_amount_non_negative_check
                CHECK (wht_amount >= 0)
        SQL);

        // Net cash = amount − wht_amount ≥ 0, as the DB authority behind the service's readable check (FR-4).
        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_wht_within_amount_check
                CHECK (wht_amount <= amount)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_wht_rate_range_check
                CHECK (wht_rate IS NULL OR (wht_rate >= 0 AND wht_rate <= 100))
        SQL);

        // A positive withholding must record the rate it used. A zero withholding may leave the rate NULL,
        // keeping a non-withholding header 8a-identical (§A3, fork 5).
        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_wht_rate_present_when_withheld_check
                CHECK (wht_amount = 0 OR wht_rate IS NOT NULL)
        SQL);

        DB::statement("COMMENT ON COLUMN supplier_payments.wht_amount IS 'Total withholding tax withheld from this payment; Cr WHT Payable. 0 for a non-withholding payment. See ADR 0021.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_rate_present_when_withheld_check');
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_rate_range_check');
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_within_amount_check');
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_wht_amount_non_negative_check');

        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropColumn(['wht_amount', 'wht_rate']);
        });
    }
};
