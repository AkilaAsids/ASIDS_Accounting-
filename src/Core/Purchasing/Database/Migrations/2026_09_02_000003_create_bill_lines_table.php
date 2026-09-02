<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bill lines — the payable-side mirror of `sales_invoice_lines`.
 *
 * Free text with a required expense account, per Gate-1 dec. 4: what makes a line postable is the expense
 * account, which is why that column is the only one here that cannot be null. It is the single departure from
 * the sales mirror — `expense_account_id` in place of `revenue_account_id`, validated by the service to be an
 * expense account (the database cannot join to `accounts` in a CHECK).
 *
 * `tax_rate` is the snapshot, stored on the line rather than re-resolved from `tax_codes` at read time: a bill
 * posted at 18% must still read 18% after the rate changes. Cascade on delete, unlike every other foreign key
 * in the module: a line has no meaning apart from its bill, and a draft's lines die with the draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Denormalised from the bill: it buys a uniform RLS policy and index prefix on every table.
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignUuid('bill_id')
                ->constrained('bills')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('line_number');

            $table->string('description');

            // Not money — a count, a weight, an hour. May be negative (a line-level correction); never zero.
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);

            // One or the other, never both — the CHECK below says so.
            $table->decimal('discount_percent', 9, 4)->nullable();
            $table->decimal('discount_amount', 19, 4)->nullable();

            // Net of the line's own discount and of its share of any header discount.
            $table->decimal('line_subtotal', 19, 4);

            // Restrict: a tax code cited by a line has to stay resolvable.
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            // The snapshot. A percentage, matching `tax_codes.rate` — 18.0000 means 18%.
            $table->decimal('tax_rate', 9, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);

            $table->decimal('line_total', 19, 4);

            // What makes the line postable. Required, and validated by the service to be an expense account
            // belonging to the same company — the database cannot join to `accounts` in a CHECK.
            $table->foreignUuid('expense_account_id')->constrained('accounts')->restrictOnDelete();

            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['bill_id', 'line_number']);
        });

        // One line per position per bill.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bill_lines_position_unique
                ON bill_lines (bill_id, line_number)
        SQL);

        // Negative is permitted (a correction); zero is not.
        DB::statement('ALTER TABLE bill_lines ADD CONSTRAINT bill_lines_quantity_check CHECK (quantity <> 0)');

        DB::statement(<<<'SQL'
            ALTER TABLE bill_lines
                ADD CONSTRAINT bill_lines_single_discount_check
                CHECK (discount_percent IS NULL OR discount_amount IS NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bill_lines
                ADD CONSTRAINT bill_lines_discount_percent_range_check
                CHECK (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bill_lines
                ADD CONSTRAINT bill_lines_tax_rate_range_check
                CHECK (tax_rate >= 0 AND tax_rate <= 100)
        SQL);

        // No tax code means no tax — a line cannot carry a rate it cannot attribute to anything.
        DB::statement(<<<'SQL'
            ALTER TABLE bill_lines
                ADD CONSTRAINT bill_lines_rate_needs_code_check
                CHECK (tax_code_id IS NOT NULL OR tax_rate = 0)
        SQL);

        // The line's own arithmetic, enforced rather than trusted.
        DB::statement(<<<'SQL'
            ALTER TABLE bill_lines
                ADD CONSTRAINT bill_lines_total_check
                CHECK (line_total = line_subtotal + tax_amount)
        SQL);

        DB::statement("COMMENT ON TABLE bill_lines IS 'Free-text bill lines with a required expense account. No product catalogue — see ADR 0019.'");
        DB::statement("COMMENT ON COLUMN bill_lines.tax_rate IS 'Snapshotted percentage. A bill posted at 18% still reads 18% after the code''s rate changes.'");
        DB::statement("COMMENT ON COLUMN bill_lines.quantity IS 'Not money. May be negative for a line-level correction; never zero.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_lines');
    }
};
