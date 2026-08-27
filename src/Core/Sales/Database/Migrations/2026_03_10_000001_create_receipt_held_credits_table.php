<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The per-receipt held-credit balance — ADR 0016 §B.
 *
 * When a customer pays more than the invoices a receipt names clear, the remainder is held as credit against
 * that customer. This table is the mutable balance for one receipt's remainder: how much was originally held,
 * how much has since been applied, and how much remains. It is deliberately a separate table from
 * `credit_applications` (the apply events), because the source-uniqueness index over `journal_entries` permits
 * exactly one non-reversing posting per source document — so a second apply against one balance needs its own
 * source row, which a single balance table cannot provide (§B, Problem #1).
 *
 * ONE ROW PER RECEIPT (Gate-1 #4)
 * -------------------------------
 * `customer_receipt_id` is UNIQUE: held credit is tracked per-receipt, not pooled per-customer, so cancellation
 * can unwind exactly the receipt that created it. `restrict` on both the customer and the receipt keeps the
 * source of the credit resolvable for as long as the balance exists.
 *
 * THE CHECKS ARE BACKSTOPS, NOT THE PRIMARY GUARD (ADR 0014 discipline)
 * --------------------------------------------------------------------
 * The service decrements `remaining_amount` under a row lock, which is what produces the readable refusal. The
 * CHECKs below are what hold if the service is ever bypassed — the two-layer guard, the analogue of the
 * invoice's `amount_paid <= total`:
 *
 *   - `original > 0` — a zero remainder creates no row at all (§C), so a zero-original row is nonsense.
 *   - `applied >= 0`, `remaining >= 0` — the over-consumption backstop.
 *   - `remaining = original - applied` — the balance tie: `remaining` and `applied` can never be written apart,
 *     the analogue of `sales_invoices_amount_due_check`.
 *   - `applied <= original` — never more consumed than was held.
 *   - `status IN ('active','cancelled')` — the one-value-widenable device.
 *   - cancelled ⇒ remaining = 0 — a cancelled record holds no usable credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_held_credits', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Restrict: the credit's owner. It never crosses to another customer.
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            // Restrict, and UNIQUE below: one held-credit record per receipt (Gate-1 #4), and the source
            // receipt stays resolvable for as long as the credit exists.
            $table->foreignUuid('customer_receipt_id')
                ->constrained('customer_receipts')
                ->restrictOnDelete();

            $table->char('currency_code', 3);

            // The remainder at record time, frozen; and the running applied/remaining pair, tied below.
            $table->decimal('original_amount', 19, 4);
            $table->decimal('applied_amount', 19, 4)->default('0');
            $table->decimal('remaining_amount', 19, 4);

            // Only `active` and `cancelled` are reachable; the CHECK below is the one-value-widenable device.
            $table->string('status', 16)->default('active');

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            // RLS convention, and the FIFO scan (§E) over a customer's active credits.
            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['company_id', 'customer_id', 'status']);
        });

        // One held-credit record per receipt.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX receipt_held_credits_receipt_unique
                ON receipt_held_credits (customer_receipt_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_held_credits
                ADD CONSTRAINT receipt_held_credits_original_positive_check
                CHECK (original_amount > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_held_credits
                ADD CONSTRAINT receipt_held_credits_applied_non_negative_check
                CHECK (applied_amount >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_held_credits
                ADD CONSTRAINT receipt_held_credits_remaining_non_negative_check
                CHECK (remaining_amount >= 0)
        SQL);

        // The tie: `remaining` and `applied` can never be written apart, the analogue of the invoice's
        // `amount_due = total - amount_paid`.
        DB::statement(<<<'SQL'
            ALTER TABLE receipt_held_credits
                ADD CONSTRAINT receipt_held_credits_balance_tie_check
                CHECK (remaining_amount = original_amount - applied_amount)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_held_credits
                ADD CONSTRAINT receipt_held_credits_applied_not_exceeding_original_check
                CHECK (applied_amount <= original_amount)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE receipt_held_credits
                ADD CONSTRAINT receipt_held_credits_status_check
                CHECK (status IN ('active', 'cancelled'))
        SQL);

        // A cancelled record holds no usable credit — its remaining must be zero.
        DB::statement(<<<'SQL'
            ALTER TABLE receipt_held_credits
                ADD CONSTRAINT receipt_held_credits_cancelled_zero_check
                CHECK (status <> 'cancelled' OR remaining_amount = 0)
        SQL);

        DB::statement("COMMENT ON TABLE receipt_held_credits IS 'Per-receipt held credit: the remainder of an overpayment, held against the customer. Mutable balance; each apply event lives in credit_applications. See ADR 0016.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_held_credits');
    }
};
