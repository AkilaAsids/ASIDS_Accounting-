<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permit a foreign exchange rate on a bill (ADR 0022 §A3, §C).
 *
 * `bills` shipped `currency_code` + a nullable `exchange_rate` held at NULL by
 * `bills_single_currency_until_fx_phase` (`2026_09_02_000002_create_bills_table.php:184`). This drops that
 * phase pin and adds the permanent shape rule: a rate is either absent (a base-currency bill) or positive.
 * The bill's money columns stay in its transaction currency (Gate-1 dec. 1); the base equivalent is derived
 * in the ledger at posting time (`BillPostingMap`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_single_currency_until_fx_phase');

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_exchange_rate_positive_check
                CHECK (exchange_rate IS NULL OR exchange_rate > 0)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT bills_exchange_rate_positive_check ON bills IS 'A rate is absent (base-currency bill) or positive. See ADR 0022.'");

        // Now that the caller may name the currency, the DB is the authority on its ISO-4217 shape — the same
        // rule companies_currency_shape_check enforces on the base currency. Guards against a mixed-case or
        // malformed code being stored and mistaken for a distinct currency (ADR 0022 §C1).
        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_currency_shape_check
                CHECK (currency_code ~ '^[A-Z]{3}$')
        SQL);

        DB::statement("COMMENT ON CONSTRAINT bills_currency_shape_check ON bills IS 'currency_code is a three-letter uppercase ISO 4217 code. See ADR 0022.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_currency_shape_check');

        DB::statement('ALTER TABLE bills DROP CONSTRAINT bills_exchange_rate_positive_check');

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_single_currency_until_fx_phase
                CHECK (exchange_rate IS NULL)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT bills_single_currency_until_fx_phase ON bills IS 'Dropped by the FX phase.'");
    }
};
