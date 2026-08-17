<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes "has this tax-code row been used?" answerable without reading every invoice line.
 *
 * `EloquentTaxRateUsageProbe` asks exactly that question, and `TaxCodeService` asks it on every update and
 * every delete — so it sits in front of two ordinary editing operations rather than on a reporting path
 * somebody runs once a month.
 *
 * The existing indexes cannot serve it. `(tenant_id, company_id)` does not lead with the column being
 * filtered, and `(sales_invoice_id, line_number)` is about locating a line within its document. Without this
 * one, changing a tax code's name scans every invoice line in the database.
 *
 * `tax_code_id` alone rather than a composite: it is a UUID, so it is selective enough by itself that a
 * leading `tenant_id` would add nothing but width. That is the same reasoning the source-document index on
 * `journal_entries` records for its own UUID column, where the tenant prefix is described as following
 * convention rather than being needed for correctness.
 *
 * Nullable, because a line may carry no tax code. PostgreSQL indexes nulls, and a partial index excluding
 * them would be marginally smaller — not worth the asymmetry with every other index on this table, and the
 * probe never searches for null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table): void {
            $table->index('tax_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table): void {
            $table->dropIndex(['tax_code_id']);
        });
    }
};
