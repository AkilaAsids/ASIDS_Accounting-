<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bill (purchase invoice) headers — the payable-side mirror of `sales_invoices`.
 *
 * Wave 7 creates the table, its constraints, and posts to the ledger in the same wave. Only `draft` and
 * `posted` are reachable this wave; `cancelled` and the payment states are reserved by the status CHECK so a
 * later feature adds behaviour rather than a CHECK-widening migration. The total invariant is folded into
 * create — unlike sales, which added it in a separate milestone — because bills post in the wave they are
 * created (ADR 0019 §A1, §A4).
 *
 * TWO DEPARTURES FROM THE SALES MIRROR
 * ------------------------------------
 *   - `supplier_invoice_number` (NOT NULL): the supplier's own number, captured at draft. It is the
 *     statutory identity of the document and the duplicate-guard key, so it replaces sales' free-text
 *     `reference` — the counterparty's reference on a bill *is* its invoice number.
 *   - a full (not partial) unique on `(company_id, supplier_id, supplier_invoice_number)`: the AP
 *     double-payment guard (Gate-1 dec. 5). A supplier assigns its own number; recording the same one twice
 *     for one supplier is the classic double-pay risk.
 *
 * NO `deleted_at`, NO CANCELLATION COLUMNS, DELIBERATELY
 * -----------------------------------------------------
 * A draft is hard-deleted; a posted bill is a statutory record and cannot be removed, so no soft-delete is
 * needed. Cancellation columns arrive with the cancel feature, exactly as sales added them later — this wave
 * only reserves `cancelled` in the status CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // The branch dimension, as on `journal_lines`: narrows a report rather than partitioning data.
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Restrict, not cascade. A bill names its supplier and that name has to stay resolvable — this is
            // the guarantee behind `SupplierService::delete()` refusing a billed supplier.
            $table->foreignUuid('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // ── Identity ────────────────────────────────────────────────────
            // The supplier's own invoice number. Required at draft (a supplier assigns it, we do not); the
            // statutory identity and the duplicate-guard key. No sales analogue.
            $table->string('supplier_invoice_number', 120);
            // The internal bill number `BILL-…`. NULL while draft, assigned inside the posting transaction so
            // an abandoned draft consumes none. Non-gapless: a bill is received, not issued.
            $table->string('number', 40)->nullable();

            // ── Dates ───────────────────────────────────────────────────────
            // The supplier's invoice date = the tax point. Drives rate resolution and the fiscal period.
            $table->date('bill_date');
            // Derived from the supplier's payment terms, overridable.
            $table->date('due_date');

            // ── Currency ────────────────────────────────────────────────────
            $table->char('currency_code', 3);
            // Held at NULL by the phase-scoped CHECK below, which the FX phase drops.
            $table->decimal('exchange_rate', 19, 10)->nullable();

            // ── Money ───────────────────────────────────────────────────────
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            // Ship now, held at zero by the phase-scoped CHECK until Wave 8, so payments add behaviour rather
            // than a migration to a populated table.
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->decimal('amount_due', 19, 4)->default(0);

            // ── Lifecycle ───────────────────────────────────────────────────
            $table->string('status', 16)->default('draft');

            // Set together with `number` and `journal_entry_id` by the posting transition. The CHECKs below
            // refuse any partial version of it.
            $table->timestampTz('posted_at')->nullable();
            $table->foreignUuid('posted_by_id')->nullable()->constrained('users')->nullOnDelete();

            // The ledger entry this bill caused. UNIQUE — the database-level guard against a bill posting
            // twice, the guarantee that holds under concurrency where a service status check does not.
            $table->foreignUuid('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['company_id', 'supplier_id', 'bill_date']);
        });

        // Unique per company once a number exists. Partial, because every draft has NULL and a plain unique
        // index would allow only one draft per company.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bills_company_number_unique
                ON bills (company_id, number)
                WHERE number IS NOT NULL
        SQL);

        // The AP double-payment guard (Gate-1 dec. 5). Full, not partial: with no soft-delete every row is
        // live, and a hard-deleted draft frees its number naturally. Per supplier per company — two suppliers
        // may both number a document INV/001, and two companies may too.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bills_company_supplier_invoice_number_unique
                ON bills (company_id, supplier_id, supplier_invoice_number)
        SQL);

        $statuses = implode("', '", ['draft', 'posted', 'partially_paid', 'paid', 'cancelled']);
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_status_check CHECK (status IN ('{$statuses}'))");

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_due_after_bill_check
                CHECK (due_date >= bill_date)
        SQL);

        /*
         * The posted boundary, stated as constraints — each partial version of the posting transition made
         * unrepresentable. `(x IS NULL) = (status = 'draft')` holds for all five states, because a cancelled
         * bill *was* posted and keeps both its number and its entry.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_number_matches_status_check
                CHECK ((number IS NULL) = (status = 'draft'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_posted_at_matches_status_check
                CHECK ((posted_at IS NULL) = (status = 'draft'))
        SQL);

        // A draft may never carry a ledger link. Stated one-directionally: a posted bill whose posting is
        // still being written is a legitimate intermediate state inside the posting transaction.
        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_draft_has_no_entry_check
                CHECK (status <> 'draft' OR journal_entry_id IS NULL)
        SQL);

        // The money invariant. Posting draws the ledger from these figures, so the header must agree with
        // itself — folded into create because a bill posts in the wave it is created.
        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_total_check
                CHECK (total = subtotal + tax_total)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_amount_due_check
                CHECK (amount_due = total - amount_paid)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_non_negative_check
                CHECK (subtotal >= 0 AND tax_total >= 0 AND total >= 0 AND amount_paid >= 0 AND discount_total >= 0)
        SQL);

        /*
         * Two phase-scoped constraints, each dropped by the phase that earns it, so the columns can ship now
         * while making it impossible to half-use them meanwhile.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_single_currency_until_fx_phase
                CHECK (exchange_rate IS NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_no_payments_until_payments_phase
                CHECK (amount_paid = 0)
        SQL);

        DB::statement("COMMENT ON TABLE bills IS 'Bill (purchase invoice) headers. Drafts are hard-deletable; posted bills are statutory records and cannot be removed. See ADR 0019.'");
        DB::statement("COMMENT ON COLUMN bills.supplier_invoice_number IS 'The supplier''s own invoice number. Required at draft; the statutory identity and duplicate-guard key.'");
        DB::statement("COMMENT ON COLUMN bills.number IS 'NULL while draft. The internal BILL- number, reserved inside the posting transaction. Non-gapless: a bill is received, not issued.'");
        DB::statement("COMMENT ON COLUMN bills.journal_entry_id IS 'UNIQUE. The database-level guard against a bill posting twice.'");
        DB::statement("COMMENT ON CONSTRAINT bills_single_currency_until_fx_phase ON bills IS 'Dropped by the FX phase.'");
        DB::statement("COMMENT ON CONSTRAINT bills_no_payments_until_payments_phase ON bills IS 'Dropped by the payments phase (Wave 8).'");
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
