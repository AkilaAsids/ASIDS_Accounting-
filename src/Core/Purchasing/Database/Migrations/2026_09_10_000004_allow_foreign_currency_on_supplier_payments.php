<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permit a foreign exchange rate on a supplier payment (ADR 0022 §A4, §D).
 *
 * `supplier_payments` shipped `currency_code` + a nullable `exchange_rate` held at NULL by
 * `supplier_payments_single_currency_until_fx_phase` (`2026_09_03_000002_create_supplier_payments_table.php:131`).
 * This drops that phase pin and adds the permanent shape rule: a rate is either absent (a base-currency
 * payment) or positive. The payment's money columns stay in its transaction currency (Gate-1 dec. 1); the
 * realised FX is derived in the ledger at posting time (`PaymentPostingMap::forForeign`). The payment freeze
 * trigger already freezes `currency_code` and `exchange_rate` by name (Wave 8a), so no freeze change is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_single_currency_until_fx_phase');

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_exchange_rate_positive_check
                CHECK (exchange_rate IS NULL OR exchange_rate > 0)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT supplier_payments_exchange_rate_positive_check ON supplier_payments IS 'A rate is absent (base-currency payment) or positive. See ADR 0022.'");

        // Now that the caller may name the currency, the DB is the authority on its ISO-4217 shape — the same
        // rule companies_currency_shape_check enforces on the base currency. Guards against a mixed-case or
        // malformed code being stored and mistaken for a distinct currency (ADR 0022 §D1).
        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_currency_shape_check
                CHECK (currency_code ~ '^[A-Z]{3}$')
        SQL);

        DB::statement("COMMENT ON CONSTRAINT supplier_payments_currency_shape_check ON supplier_payments IS 'currency_code is a three-letter uppercase ISO 4217 code. See ADR 0022.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_currency_shape_check');

        DB::statement('ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_exchange_rate_positive_check');

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
                ADD CONSTRAINT supplier_payments_single_currency_until_fx_phase
                CHECK (exchange_rate IS NULL)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT supplier_payments_single_currency_until_fx_phase ON supplier_payments IS 'Dropped by the FX phase.'");
    }
};
