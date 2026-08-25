<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer receipt headers — money arriving against a customer's issued invoices.
 *
 * The mirror of `sales_invoices` on the receiving side (ADR 0014 §A), and it follows that table's shape
 * verbatim wherever the two share a concern: the denormalised `tenant_id`/`company_id`, the `restrict` on the
 * customer so it stays resolvable, the branch dimension, the UNIQUE `journal_entry_id` that is the
 * database-level guard against a document posting twice.
 *
 * POSTED-ONLY, SO `number` IS NOT NULL
 * ------------------------------------
 * There is no draft state this wave (Gate-1 #1: cancellation/reversal deferred, a posted receipt is
 * immutable). A receipt therefore carries its gapless `RCT-…` number from insert, and the company-number
 * unique index below is a plain unique — not the partial `WHERE number IS NOT NULL` the invoice needs only
 * because a draft invoice has a null number. The `status` CHECK is written as a one-value `IN ('posted')` so a
 * later reversal sub-slice widens it deliberately, exactly as `sales_invoices_status_check` shipped covering
 * all five states from the start.
 *
 * NO `exchange_rate`
 * ------------------
 * Multi-currency receipts are FX-phase work. Adding the column now would half-build them; the service holds a
 * receipt to the company's base currency instead (ADR 0014 §A).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // The branch dimension, as on the invoice: narrows a report rather than partitioning data.
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Restrict, not cascade. A receipt names its customer and that name has to stay resolvable, the
            // same guarantee `sales_invoices.customer_id` gives.
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();

            // ── Identity ────────────────────────────────────────────────────
            // NOT NULL: there is no draft state this wave, so a receipt carries its gapless number from insert.
            $table->string('number', 40);
            // The external reference — a cheque number or a bank transaction id.
            $table->string('reference', 120)->nullable();

            // ── Dates ───────────────────────────────────────────────────────
            // The tax point that selects the fiscal period the posting lands in.
            $table->date('receipt_date');

            // ── Currency and money ──────────────────────────────────────────
            $table->char('currency_code', 3);
            // At the ledger's scale of four, matching `Money::SCALE` and `numeric(19,4)` exactly.
            $table->decimal('amount', 19, 4);

            // Backed by the `PaymentMethod` enum, CHECK-constrained below.
            $table->string('payment_method', 16);

            // The asset account the receipt debits (Gate-1 #3). Restrict: the account it posted to has to stay
            // resolvable. Validated postable/active/asset/in-company by the service and the posting map — the
            // database cannot join to `accounts` in a CHECK.
            $table->foreignUuid('bank_account_id')->constrained('accounts')->restrictOnDelete();

            // ── Lifecycle ───────────────────────────────────────────────────
            // Only `posted` is reachable this wave; the one-value CHECK below prepares the reversal boundary.
            $table->string('status', 16)->default('posted');

            // The one ledger entry this receipt caused. UNIQUE — the database-level guard against a double
            // posting, exactly as on the invoice.
            $table->foreignUuid('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();

            $table->timestampTz('posted_at')->nullable();
            // Who recorded it, written at insert and frozen by the immutability trigger — the same "written
            // here or never" rule as the invoice's `issued_by_id`. Null when the system records without a person.
            $table->foreignUuid('posted_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['company_id', 'customer_id', 'receipt_date']);
        });

        // Unique per company. Not partial — `number` is never null here, unlike the invoice's draft state.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customer_receipts_company_number_unique
                ON customer_receipts (company_id, number)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE customer_receipts
                ADD CONSTRAINT customer_receipts_amount_positive_check
                CHECK (amount > 0)
        SQL);

        // One value now; a later reversal sub-slice widens it deliberately.
        DB::statement(<<<'SQL'
            ALTER TABLE customer_receipts
                ADD CONSTRAINT customer_receipts_status_check
                CHECK (status IN ('posted'))
        SQL);

        // The four `PaymentMethod` cases, held at the database as well as the type — a value the enum does not
        // know is refused here rather than stored.
        DB::statement(<<<'SQL'
            ALTER TABLE customer_receipts
                ADD CONSTRAINT customer_receipts_payment_method_check
                CHECK (payment_method IN ('cash', 'bank_transfer', 'cheque', 'card'))
        SQL);

        DB::statement("COMMENT ON TABLE customer_receipts IS 'Customer receipt headers. Posted-only and immutable this wave; cancellation/reversal is a deferred sub-slice. See ADR 0014.'");
        DB::statement("COMMENT ON COLUMN customer_receipts.journal_entry_id IS 'UNIQUE. The database-level guard against a receipt posting twice.'");
        DB::statement("COMMENT ON COLUMN customer_receipts.bank_account_id IS 'The asset account debited. Validated postable/active/asset/in-company by the service; no CHECK can join to accounts.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipts');
    }
};
