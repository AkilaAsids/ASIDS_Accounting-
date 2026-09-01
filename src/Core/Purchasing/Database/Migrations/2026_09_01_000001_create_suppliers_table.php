<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The parties a company buys from.
 *
 * The payable-side mirror of `customers`. Owned by a **company**, not a tenant. Two companies in one
 * workspace that both buy from the same shop keep separate supplier records, and they must: the payable
 * balance, the credit terms and the statement all belong to one set of books. Sharing the record would
 * mean sharing the balance.
 *
 * `branch_id` is advisory — the branch that owns the relationship — and nullable. It narrows a report
 * rather than partitioning the data, exactly as `journal_lines.branch_id` does. A supplier serving two
 * branches is one supplier.
 *
 * A field-by-field mirror of the `customers` table less the two deferred columns (`credit_limit` and the
 * AP/receivable account), which have no defined payable-side meaning until bills exist in Wave 7
 * (ADR 0018 §B2). `tax_identification_number` is kept, pre-provisioning Wave 8 supplier-WHT/compliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Denormalised from `company_id`, as everywhere else in the platform: it costs 16 bytes a
            // row and buys a uniform index prefix and a uniform RLS policy on every table.
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Restrict rather than cascade: losing a branch must not take its suppliers with it.
            // Nulled instead, because the branch is a dimension and the supplier survives it.
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // ── Identity ────────────────────────────────────────────────────
            // A code, not a gapless number. No tax authority audits supplier codes for completeness,
            // so the row lock gapless numbering costs would buy nothing. Unique per company,
            // case-insensitively, via the expression index below.
            $table->string('code', 32);
            $table->string('name');
            $table->string('legal_name')->nullable();

            // ── Statutory registrations (Sri Lanka first, generic shape) ────
            // The TIN is retained (Gate-1 decision 4), pre-provisioning Wave 8 supplier-WHT/compliance.
            $table->string('tax_identification_number', 64)->nullable();
            $table->string('vat_registration_number', 64)->nullable();
            $table->boolean('is_vat_registered')->default(false);

            // ── Contact ─────────────────────────────────────────────────────
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();

            // ── Address ─────────────────────────────────────────────────────
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 96)->nullable();
            $table->string('district', 96)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->char('country_code', 2)->nullable();

            // ── Billing terms ───────────────────────────────────────────────
            // Days from bill date to due date — the terms this company *receives* from the supplier.
            // Zero means due on receipt, which is a real term and not a missing value — hence a default
            // rather than nullable.
            $table->unsignedSmallInteger('payment_terms_days')->default(30);

            $table->text('notes')->nullable();

            // ── Lifecycle ───────────────────────────────────────────────────
            $table->string('status', 16)->default('active');
            $table->timestampTz('archived_at')->nullable();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Leading with tenant_id: the platform's index convention, and it matches the RLS
            // predicate so the planner gets a selective first column.
            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['company_id', 'branch_id']);
        });

        // Case-insensitive uniqueness per company, ignoring soft-deleted rows — the same expression
        // index `customers_company_code_unique` uses, and for the same reason: "ABC" and "abc" are one
        // supplier to everyone except a naive unique constraint.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX suppliers_company_code_unique
                ON suppliers (company_id, upper(code))
                WHERE deleted_at IS NULL
        SQL);

        // Type-ahead without a table scan, matching how `customers` supports its picker.
        DB::statement('CREATE INDEX suppliers_name_trgm ON suppliers USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX suppliers_code_trgm ON suppliers USING gin (code gin_trgm_ops)');

        $statuses = implode("', '", ['active', 'inactive', 'archived']);
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_check CHECK (status IN ('{$statuses}'))");

        // The archive timestamp and the status cannot disagree. Phase 2 learned this the hard way on
        // `fiscal_periods`, where a mass update moved `status` and left `closed_at` behind and the
        // constraint caught it — which is the constraint doing its job, not being awkward.
        DB::statement(<<<'SQL'
            ALTER TABLE suppliers
                ADD CONSTRAINT suppliers_archived_check
                CHECK ((status = 'archived') = (archived_at IS NOT NULL))
        SQL);

        // A VAT number without registration, or registration without a number, is one of them being
        // wrong. `companies` states the same rule about its own registrations.
        DB::statement(<<<'SQL'
            ALTER TABLE suppliers
                ADD CONSTRAINT suppliers_vat_registration_check
                CHECK (NOT is_vat_registered OR vat_registration_number IS NOT NULL)
        SQL);

        DB::statement("COMMENT ON TABLE suppliers IS 'Parties a company buys from. Owned by a company, because the payable balance and credit terms belong to one set of books.'");
        DB::statement("COMMENT ON COLUMN suppliers.tax_identification_number IS 'Retained for later WHT/compliance (Gate-1 decision 4).'");
        DB::statement("COMMENT ON COLUMN suppliers.branch_id IS 'Advisory dimension. Narrows a report; does not partition the data.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
