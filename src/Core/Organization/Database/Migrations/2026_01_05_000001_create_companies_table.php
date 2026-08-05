<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company is a legal entity that keeps its own books: its own chart of
 * accounts, fiscal calendar, base currency and statutory registrations. One
 * tenant may own many (a group of SMEs under common ownership is the norm in the
 * Sri Lankan market this product targets).
 *
 * Two fields are immutable once the company has posted a journal entry, and
 * later phases enforce that: `base_currency_code` and the fiscal year start.
 * Changing either would silently reinterpret every historical balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // ── Identity ───────────────────────────────────────────────────
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code', 24);
            $table->string('slug', 120);

            // ── Statutory registrations (Sri Lanka first, generic shape) ────
            $table->string('registration_number', 64)->nullable();
            $table->string('tax_identification_number', 64)->nullable();
            $table->string('vat_registration_number', 64)->nullable();
            $table->string('svat_registration_number', 64)->nullable();
            $table->boolean('is_vat_registered')->default(false);
            $table->boolean('is_svat_registered')->default(false);
            $table->string('business_type', 48)->nullable();
            $table->string('industry', 96)->nullable();
            $table->date('established_on')->nullable();

            // ── Accounting configuration ───────────────────────────────────
            $table->char('base_currency_code', 3);
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->unsignedTinyInteger('fiscal_year_start_day')->default(1);
            // Presentation precision. Ledger arithmetic itself is exact.
            $table->unsignedTinyInteger('currency_precision')->default(2);

            // ── Locale ─────────────────────────────────────────────────────
            $table->char('country_code', 2);
            $table->string('timezone', 64);
            $table->string('locale', 8)->default('en');

            // ── Contact & address ──────────────────────────────────────────
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 96)->nullable();
            $table->string('district', 96)->nullable();
            $table->string('postal_code', 24)->nullable();

            // ── Branding ───────────────────────────────────────────────────
            $table->string('logo_path')->nullable();

            // ── Lifecycle ──────────────────────────────────────────────────
            $table->string('status', 24)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestampTz('archived_at')->nullable();

            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'name']);
        });

        // Codes and slugs are unique inside a tenant, case-insensitively.
        DB::statement('CREATE UNIQUE INDEX companies_tenant_code_unique ON companies (tenant_id, upper(code)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX companies_tenant_slug_unique ON companies (tenant_id, lower(slug)) WHERE deleted_at IS NULL');

        // Exactly one default company per tenant.
        DB::statement('CREATE UNIQUE INDEX companies_one_default_per_tenant ON companies (tenant_id) WHERE is_default AND deleted_at IS NULL');

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_fiscal_month_check CHECK (fiscal_year_start_month BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_fiscal_day_check CHECK (fiscal_year_start_day BETWEEN 1 AND 28)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_precision_check CHECK (currency_precision BETWEEN 0 AND 6)');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_currency_shape_check CHECK (base_currency_code ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_country_shape_check CHECK (country_code ~ '^[A-Z]{2}$')");
        // SVAT is a Sri Lankan suspended-VAT scheme; it presupposes VAT.
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_svat_requires_vat_check CHECK (NOT is_svat_registered OR is_vat_registered)');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_archived_consistency_check CHECK ((status = \'archived\') = (archived_at IS NOT NULL))');

        // The fiscal year start day is capped at 28 deliberately: allowing the
        // 29th to 31st would make the fiscal calendar undefined in February.
        DB::statement('COMMENT ON COLUMN companies.fiscal_year_start_day IS \'Capped at 28 so the fiscal calendar is well defined in every month.\'');

        // Complete the forward reference declared in the users migration.
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('default_company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['default_company_id']);
        });

        Schema::dropIfExists('companies');
    }
};
