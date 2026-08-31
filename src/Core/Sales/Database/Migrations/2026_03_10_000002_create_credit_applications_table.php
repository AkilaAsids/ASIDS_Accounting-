<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One apply event: this much of a held credit reclassified onto that invoice — ADR 0016 §B.
 *
 * Each row is its own journal-entry source document, which is why apply-credit needs a table of its own rather
 * than a second balance on `receipt_held_credits`. The source-uniqueness index over `journal_entries` permits
 * exactly one non-reversing posting per source document (Problem #1): a second apply against one held credit
 * would collide if it cited the receipt or the invoice, so every apply is its own row, cited as its own source.
 *
 * WHAT IS AND IS NOT UNIQUE
 * -------------------------
 * `journal_entry_id` is UNIQUE — one posting per application, the double-post backstop, exactly as on the
 * receipt. There is deliberately no `(held_credit, invoice)` uniqueness: a customer may legitimately apply one
 * receipt's credit to the same invoice in two separate events, and the balance CHECKs on `receipt_held_credits`
 * — not an index — are what bound the total consumed (§B).
 *
 * `restrict` throughout: the consumed credit, the reduced invoice and the posting must all stay resolvable, the
 * same "history stays permanent" discipline the receipt allocations follow. `amount > 0` is the only CHECK a
 * single row can make on its own — a zero application is noise and a negative one would un-apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Denormalised for the cross-customer guard and the audit trail.
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            // The credit consumed by this event.
            $table->foreignUuid('receipt_held_credit_id')
                ->constrained('receipt_held_credits')
                ->restrictOnDelete();

            // The invoice reduced by this event.
            $table->foreignUuid('sales_invoice_id')
                ->constrained('sales_invoices')
                ->restrictOnDelete();

            $table->char('currency_code', 3);
            $table->decimal('amount', 19, 4);

            // UNIQUE — the reclassification JV, one posting per application.
            $table->foreignUuid('journal_entry_id')
                ->unique()
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->timestampTz('applied_at');
            $table->foreignUuid('applied_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['receipt_held_credit_id']);
            $table->index(['sales_invoice_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_applications
                ADD CONSTRAINT credit_applications_amount_positive_check
                CHECK (amount > 0)
        SQL);

        DB::statement("COMMENT ON TABLE credit_applications IS 'One apply-credit event: held credit reclassified onto an invoice. Each row is its own journal-entry source document. See ADR 0016.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_applications');
    }
};
