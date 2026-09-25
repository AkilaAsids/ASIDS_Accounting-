<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Release the foreign-currency overlay on the ledger — the shared change at the heart of the FX phase
 * (ADR 0022 §A2, §B).
 *
 * `journal_lines` shipped with `transaction_currency_code`/`transaction_amount`/`exchange_rate` and two rules
 * (`2026_02_03_000003_create_journal_lines_table.php:109-125`): `journal_lines_currency_columns_check` (the
 * permanent shape authority — the three columns are all-present-or-all-absent, rate positive) and
 * `journal_lines_single_currency_until_fx_phase` (the phase pin that holds the overlay at NULL). This drops
 * **exactly** the phase pin; the shape rule stays.
 *
 * This is the one migration with a ledger-wide blast radius: it affects every posting path, not just
 * purchasing. The overlay is written only by the FX-aware purchasing maps (`JournalService::writeLines`
 * populates the columns only when a line carries a `ForeignAmount`); every other path leaves them NULL. The
 * broad base-path regression (ADR §H) is the evidence that nothing else began populating the overlay once the
 * pin came off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_single_currency_until_fx_phase');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_single_currency_until_fx_phase
                CHECK (transaction_currency_code IS NULL)
        SQL);
    }
};
