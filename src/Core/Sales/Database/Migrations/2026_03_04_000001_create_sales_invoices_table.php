<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales invoice headers.
 *
 * Milestone 4 creates the table and the constraints; only `draft` is reachable until Milestone 5 adds
 * issuing. The structure for that transition is here already — see ADR 0007 — because a CHECK costs nothing
 * now and makes a whole class of invalid state unrepresentable before any code exists to create it.
 *
 * NO `deleted_at`, DELIBERATELY
 * ----------------------------
 * `customers` and `tax_codes` both soft-delete; this does not. A draft that was never issued is not an
 * accounting document — nothing cites it, no return reports it, no auditor will ask about it — so deleting
 * one removes the row. An invoice that *has* been issued cannot be deleted at all, which is why no
 * soft-delete column is needed rather than why one is. Retention rules for issued and cancelled documents
 * belong to Milestone 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // The branch dimension, as on `journal_lines`: narrows a report rather than partitioning data.
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Restrict, not cascade. An invoice names its customer and that name has to stay resolvable —
            // `CustomerService` already refuses to delete a customer with invoices, and this is the
            // guarantee behind that refusal rather than a duplicate of it.
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();

            // ── Identity ────────────────────────────────────────────────────
            // NULL while draft. Gapless numbering is reserved inside the issuing transaction in Milestone 5,
            // precisely so an abandoned draft consumes none — a number handed to a draft that is later
            // deleted leaves a gap in a series a tax authority may audit for completeness.
            $table->string('number', 40)->nullable();
            // The customer's own reference — a purchase order number, usually. Free text, and the only
            // human-facing identifier a draft has.
            $table->string('reference', 120)->nullable();

            // ── Dates ───────────────────────────────────────────────────────
            // The tax point. Drives which rate resolves and, from Milestone 5, which fiscal period the
            // posting lands in.
            $table->date('invoice_date');
            // Derived from the customer's payment terms, overridable.
            $table->date('due_date');

            // ── Currency ────────────────────────────────────────────────────
            $table->char('currency_code', 3);
            // Present so the FX phase adds behaviour rather than a column to a populated table. Held at NULL
            // by the phase-scoped CHECK below, which that phase drops.
            $table->decimal('exchange_rate', 19, 10)->nullable();

            // ── Money ───────────────────────────────────────────────────────
            // All at the ledger's scale of four. `subtotal` is net of line discounts; `discount_total` is
            // the header discount allocated across lines.
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            // Ship now, fixed at zero until Phase 4, so payments add behaviour rather than a migration to a
            // populated table. The phase-scoped CHECK below holds them consistent meanwhile.
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->decimal('amount_due', 19, 4)->default(0);

            // ── Lifecycle ───────────────────────────────────────────────────
            $table->string('status', 16)->default('draft');

            // Set together with `number` and `journal_entry_id` by the Milestone 5 issuing transition. The
            // CHECKs below refuse any partial version of it.
            $table->timestampTz('issued_at')->nullable();
            $table->foreignUuid('issued_by_id')->nullable()->constrained('users')->nullOnDelete();

            // The ledger entry this invoice caused. UNIQUE, and that is the database-level guard against a
            // document posting twice — the guarantee that holds under concurrency, where a service check on
            // the invoice's status does not. It has nothing to guard until Milestone 5, which is the right
            // order: the constraint is in place before the code depending on it.
            $table->foreignUuid('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['company_id', 'customer_id', 'invoice_date']);
        });

        // Unique per company once a number exists. Partial, because every draft has NULL and a plain unique
        // index would allow only one draft per company.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sales_invoices_company_number_unique
                ON sales_invoices (company_id, number)
                WHERE number IS NOT NULL
        SQL);

        $statuses = implode("', '", ['draft', 'issued', 'partially_paid', 'paid', 'cancelled']);
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_status_check CHECK (status IN ('{$statuses}'))");

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_due_after_invoice_check
                CHECK (due_date >= invoice_date)
        SQL);

        /*
         * The issued boundary, stated as constraints.
         *
         * Each of these makes an inconsistent state unrepresentable rather than merely refused. A draft
         * carrying a number, an issued invoice without one, a draft already pointing at a ledger entry — all
         * impossible before any code exists that could produce them.
         *
         * Note the equality form: `(x IS NULL) = (status = 'draft')` holds for all five states, because a
         * cancelled invoice *was* issued and keeps both its number and its entry.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_number_matches_status_check
                CHECK ((number IS NULL) = (status = 'draft'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_issued_at_matches_status_check
                CHECK ((issued_at IS NULL) = (status = 'draft'))
        SQL);

        // A draft may never carry a ledger link. Stated one-directionally on purpose: an issued invoice
        // whose posting is still being written is a legitimate intermediate state inside the Milestone 5
        // transaction, so the converse is not asserted.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_draft_has_no_entry_check
                CHECK (status <> 'draft' OR journal_entry_id IS NULL)
        SQL);

        // The money invariant. Enforced here rather than trusted to whichever service last touched the row,
        // because a total that disagrees with what is outstanding is a figure two reports will disagree
        // about.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_amount_due_check
                CHECK (amount_due = total - amount_paid)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_non_negative_check
                CHECK (subtotal >= 0 AND tax_total >= 0 AND total >= 0 AND amount_paid >= 0 AND discount_total >= 0)
        SQL);

        /*
         * Two phase-scoped constraints, each dropped by the phase that earns it.
         *
         * They exist so the columns can ship now — the alternative is adding columns to a populated table
         * later — while making it impossible to half-use them in the meantime. The same device
         * `journal_lines_single_currency_until_fx_phase` already uses.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_single_currency_until_fx_phase
                CHECK (exchange_rate IS NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoices
                ADD CONSTRAINT sales_invoices_no_payments_until_payments_phase
                CHECK (amount_paid = 0)
        SQL);

        DB::statement("COMMENT ON TABLE sales_invoices IS 'Sales invoice headers. Drafts are hard-deletable; issued invoices are statutory records and cannot be removed. See ADR 0007.'");
        DB::statement("COMMENT ON COLUMN sales_invoices.number IS 'NULL while draft. Gapless, reserved inside the Milestone 5 issuing transaction so an abandoned draft consumes none.'");
        DB::statement("COMMENT ON COLUMN sales_invoices.journal_entry_id IS 'UNIQUE. The database-level guard against a document posting twice.'");
        DB::statement("COMMENT ON CONSTRAINT sales_invoices_single_currency_until_fx_phase ON sales_invoices IS 'Dropped by the FX phase.'");
        DB::statement("COMMENT ON CONSTRAINT sales_invoices_no_payments_until_payments_phase ON sales_invoices IS 'Dropped by the payments phase (Phase 4).'");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
