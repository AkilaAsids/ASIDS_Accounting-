<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt allocation lines — the subledger detail, never itself a posting.
 *
 * A child of `customer_receipts` in the header/child shape of `sales_invoice_lines`, including the
 * denormalised `tenant_id`/`company_id` so RLS and indexing stay uniform (RLS is not transitive — the child
 * carries its own tenant key and gets its own policy).
 *
 * Only the receipt as a whole posts once to the ledger (Dr Bank, Cr Trade Receivables). These lines exist
 * purely to drive each invoice's `amount_paid`/`amount_due` and to give an auditor the "this receipt paid
 * these invoices, in these amounts" trail the single netted ledger entry cannot show.
 *
 * WHAT IS AND IS NOT A CHECK
 * --------------------------
 * `amount > 0` is a CHECK: a zero line is noise and a negative one would silently un-pay an invoice. The
 * `(receipt, invoice)` uniqueness is a CHECK: one figure per invoice per receipt, so the ≤-`amount_due` rule
 * has a single number to reason about and a double-submit collides at the index. But "per-allocation ≤ invoice
 * `amount_due`" and "Σ allocations = receipt amount" cannot be CHECKs — a CHECK cannot join to another table —
 * so both are enforced in `ReceiptService` under a row lock, backed by the invoice-level
 * `amount_paid <= total` CHECK added in the same stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Cascade: a line has no life apart from its receipt.
            $table->foreignUuid('customer_receipt_id')
                ->constrained('customer_receipts')
                ->cascadeOnDelete();

            // Restrict: the allocated invoice must stay resolvable.
            $table->foreignUuid('sales_invoice_id')
                ->constrained('sales_invoices')
                ->restrictOnDelete();

            $table->decimal('amount', 19, 4);

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['sales_invoice_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_allocations
                ADD CONSTRAINT receipt_allocations_amount_positive_check
                CHECK (amount > 0)
        SQL);

        // One allocation line per invoice per receipt.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX receipt_allocations_unique_invoice
                ON receipt_allocations (customer_receipt_id, sales_invoice_id)
        SQL);

        DB::statement("COMMENT ON TABLE receipt_allocations IS 'Receipt subledger detail: which invoices a receipt paid, in what amounts. Not itself a ledger posting. See ADR 0014.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_allocations');
    }
};
