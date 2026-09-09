<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-supplier withholding-tax configuration (ADR 0021 §A2, Gate-1 dec. 1).
 *
 * `suppliers.tax_identification_number` was retained at Wave 6 "pre-provisioning Wave 8 supplier-WHT/
 * compliance" (`2026_09_01_000001_create_suppliers_table.php:56-57`). This adds the rate it was reserved for:
 * whether a supplier's payments withhold by default, and at what percentage.
 *
 * Default `wht_applicable = false` — an existing supplier is non-withholding until configured, so every
 * existing supplier and every payment for one stays byte-for-byte Wave 8a (AC-1.2). `wht_rate` is a
 * *percentage* (`5.00` = 5%), nullable, capped at 100 by the range CHECK. The biconditional CHECK mirrors
 * `suppliers_archived_check`'s shape (`create_suppliers_table.php:116-120`): a rate is present exactly when
 * the supplier withholds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->boolean('wht_applicable')->default(false)->after('is_vat_registered');
            // A percentage (5.00 = 5%), not a fraction. decimal(5,2) holds 0.00–999.99; the range CHECK
            // caps it at 100. Nullable — a non-withholding supplier has no rate.
            $table->decimal('wht_rate', 5, 2)->nullable()->after('wht_applicable');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE suppliers
                ADD CONSTRAINT suppliers_wht_rate_range_check
                CHECK (wht_rate IS NULL OR (wht_rate >= 0 AND wht_rate <= 100))
        SQL);

        // Required IFF applicable — the same biconditional shape as suppliers_archived_check.
        DB::statement(<<<'SQL'
            ALTER TABLE suppliers
                ADD CONSTRAINT suppliers_wht_applicable_requires_rate_check
                CHECK (wht_applicable = (wht_rate IS NOT NULL))
        SQL);

        DB::statement("COMMENT ON COLUMN suppliers.wht_rate IS 'Withholding-tax percentage (5.00 = 5%%). Present iff wht_applicable. See ADR 0021.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_wht_applicable_requires_rate_check');
        DB::statement('ALTER TABLE suppliers DROP CONSTRAINT suppliers_wht_rate_range_check');

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn(['wht_applicable', 'wht_rate']);
        });
    }
};
