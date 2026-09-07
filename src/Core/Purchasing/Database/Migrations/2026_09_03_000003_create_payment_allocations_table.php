<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment allocations — how much of a payment settles which bill (ADR 0020 §A3).
 *
 * The allocation subledger behind the Trade Payables control account: the payment posts one grouped
 * `Dr Trade Payables` line, and the per-bill detail lives here (mirror how `bill_lines` detail lives apart
 * from the one grouped payable credit). Mirrors `bill_lines`
 * (`2026_09_02_000003_create_bill_lines_table.php:26-75`), minus the tax/line-arithmetic machinery — an
 * allocation is one bill and one amount.
 *
 * Full allocation is required (Gate-1 dec. 2): a payment's allocations must sum to its amount, enforced at
 * commit by the deferred constraint trigger (§A5). Per-bill amount is capped at the bill's current
 * `amount_due` (dec. 3) in the service under a row lock and backstopped by `bills_amount_due_non_negative_check`
 * — not a CHECK here, because a CHECK cannot join to `bills`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Its own RLS policy — RLS is not transitive (§A6).
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Denormalised from the payment for a uniform RLS policy and index prefix — mirror
            // `bill_lines:34-36`.
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // An allocation has no meaning apart from its payment — mirror `bill_lines.bill_id`
            // (`bill_lines:38-40`). Cascade only ever fires if the payment is hard-deleted, which §A5 forbids.
            $table->foreignUuid('supplier_payment_id')
                ->constrained('supplier_payments')
                ->cascadeOnDelete();

            // The settled bill must stay resolvable; a posted bill cannot be hard-deleted anyway.
            $table->foreignUuid('bill_id')->constrained('bills')->restrictOnDelete();

            // A negative allocation would be a refund/debit note — out of scope. CHECK > 0 below.
            $table->decimal('amount', 19, 4);

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            // "Which payments settled this bill?" and to index the FK (PostgreSQL does not index FKs
            // automatically). The unique index below already prefixes `supplier_payment_id`.
            $table->index(['bill_id']);
        });

        // One allocation row per bill per payment (Gate-1). A bill is not split across two rows within one
        // payment; a second payment against the same bill is a separate payment row.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_allocations_payment_bill_unique
                ON payment_allocations (supplier_payment_id, bill_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_allocations
                ADD CONSTRAINT payment_allocations_amount_positive_check
                CHECK (amount > 0)
        SQL);

        DB::statement("COMMENT ON TABLE payment_allocations IS 'How much of a supplier payment settles which bill — the allocation subledger behind Trade Payables. See ADR 0020.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
