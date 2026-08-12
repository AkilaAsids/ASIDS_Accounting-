<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invoice header's arithmetic, enforced by the database.
 *
 * `total = subtotal + tax_total`. Milestone 4 left this to the service and proved it in tests; Milestone 5 makes
 * it a constraint, because issuing is the point at which the header stops being a working figure and becomes an
 * accounting assertion. From that moment the ledger is posted from these numbers, so a header that disagreed
 * with itself would put a wrong debit against a right set of credits — and the deferred balance trigger on
 * `journal_lines` would refuse the entry, leaving an invoice that cannot be issued and no explanation of why.
 *
 * `amount_due = total - amount_paid` is already enforced this way, and the two are the same kind of statement.
 * Adding this one now rather than at Milestone 4 was deliberate: the schema had been reviewed and approved, and
 * altering it mid-milestone was not a trade worth making. This is the boundary where it earns its place.
 *
 * Note `subtotal` is net of every discount, line and header alike, so no discount term appears here. That
 * composition is recorded as a deferred consideration in ADR 0007.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No backfill or repair step: `SalesInvoiceService` has computed all three figures together since
        // Milestone 4, so every existing row already satisfies this. Postgres validates the constraint against
        // current data as it is added, so a row that did not would fail this migration loudly rather than
        // leaving the constraint unenforced.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_total_check
                CHECK (total = subtotal + tax_total)
        SQL);

        DB::statement("COMMENT ON CONSTRAINT sales_invoices_total_check ON sales_invoices IS 'Issuing posts the ledger from these figures, so the header must agree with itself.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_total_check');
    }
};
