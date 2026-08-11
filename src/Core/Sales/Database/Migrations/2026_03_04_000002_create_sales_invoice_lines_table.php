<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales invoice lines.
 *
 * Free text with a required revenue account, per decision A1/D8: there is no product catalogue and a line
 * does not reference one. What makes a line postable is the revenue account, which is why that column is the
 * only one here that cannot be null.
 *
 * WHY THE RATE IS SNAPSHOTTED
 * ---------------------------
 * `tax_rate` is stored on the line rather than joined from `tax_codes` at read time. An invoice issued at 18%
 * must still read 18% after the rate changes, and ADR 0006 made a rate change a new row precisely so history
 * survives — but a line that resolved its rate afresh on every read would defeat that. The same reasoning as
 * `journal_lines` storing amounts rather than recomputing them.
 *
 * Cascade on delete, unlike every other foreign key in the module. A line has no meaning apart from its
 * invoice: it is not independently addressable, nothing else references it, and a draft's lines should die
 * with the draft. Issued invoices cannot be deleted at all, so the cascade can only ever fire for a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Denormalised from the invoice, as everywhere else in the platform: it buys a uniform RLS
            // policy and a uniform index prefix on every table.
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignUuid('sales_invoice_id')
                ->constrained('sales_invoices')
                ->cascadeOnDelete();

            // Ordering within the document, so an invoice redisplays and reprints as it was entered.
            $table->unsignedSmallInteger('line_number');

            $table->string('description');

            // Not money — a count, a weight, an hour. May be negative, which is how a line-level correction
            // is expressed on an invoice that is otherwise positive.
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);

            // One or the other, never both — the CHECK below says so. A percentage is what a salesperson
            // negotiates; a fixed amount is what a manager approves, and storing both would leave the
            // question of which won.
            $table->decimal('discount_percent', 9, 4)->nullable();
            $table->decimal('discount_amount', 19, 4)->nullable();

            // Net of the line's own discount and of its share of any header discount.
            $table->decimal('line_subtotal', 19, 4);

            // Restrict: a tax code cited by a line has to stay resolvable, which is the guarantee behind
            // `TaxCodeService` refusing to delete a code that has been applied.
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            // The snapshot. A percentage, matching `tax_codes.rate` — 18.0000 means 18%.
            $table->decimal('tax_rate', 9, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);

            $table->decimal('line_total', 19, 4);

            // What makes the line postable. Required, and validated by the service to be an income account
            // belonging to the same company — the database cannot join to `accounts` in a CHECK.
            $table->foreignUuid('revenue_account_id')->constrained('accounts')->restrictOnDelete();

            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['sales_invoice_id', 'line_number']);
        });

        // One line per position per invoice. Without it a reordering bug produces two line 3s and the
        // document reprints in an order nobody chose.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sales_invoice_lines_position_unique
                ON sales_invoice_lines (sales_invoice_id, line_number)
        SQL);

        // A zero-quantity line contributes nothing and is almost always a half-finished entry. Negative is
        // permitted; zero is not.
        DB::statement('ALTER TABLE sales_invoice_lines ADD CONSTRAINT sales_invoice_lines_quantity_check CHECK (quantity <> 0)');

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoice_lines
                ADD CONSTRAINT sales_invoice_lines_single_discount_check
                CHECK (discount_percent IS NULL OR discount_amount IS NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoice_lines
                ADD CONSTRAINT sales_invoice_lines_discount_percent_range_check
                CHECK (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))
        SQL);

        // A percentage, bounded as `tax_codes.rate` is. A rate entered as basis points would otherwise
        // multiply the line by eighteen.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoice_lines
                ADD CONSTRAINT sales_invoice_lines_tax_rate_range_check
                CHECK (tax_rate >= 0 AND tax_rate <= 100)
        SQL);

        // No tax code means no tax. Stated so a line cannot carry a rate it cannot attribute to anything —
        // which is the shape a partially-applied edit produces, and the shape a VAT return cannot explain.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoice_lines
                ADD CONSTRAINT sales_invoice_lines_rate_needs_code_check
                CHECK (tax_code_id IS NOT NULL OR tax_rate = 0)
        SQL);

        // The line's own arithmetic, enforced rather than trusted. A line whose total disagrees with its
        // parts is a line that will make the invoice disagree with the ledger.
        DB::statement(<<<'SQL'
            ALTER TABLE sales_invoice_lines
                ADD CONSTRAINT sales_invoice_lines_total_check
                CHECK (line_total = line_subtotal + tax_amount)
        SQL);

        DB::statement("COMMENT ON TABLE sales_invoice_lines IS 'Free-text invoice lines with a required revenue account. No product catalogue — see ADR 0007 decision A1.'");
        DB::statement("COMMENT ON COLUMN sales_invoice_lines.tax_rate IS 'Snapshotted percentage. An invoice issued at 18% still reads 18% after the code''s rate changes.'");
        DB::statement("COMMENT ON COLUMN sales_invoice_lines.quantity IS 'Not money. May be negative for a line-level correction; never zero.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
