<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The parties a company invoices.
 *
 * Owned by a **company**, not a tenant. Two companies in one workspace that both sell to the same
 * shop keep separate customer records, and they must: the receivable balance, the credit terms and
 * the statement all belong to one set of books. Sharing the record would mean sharing the balance.
 *
 * `branch_id` is advisory — the branch that owns the relationship — and nullable. It narrows a report
 * rather than partitioning the data, exactly as `journal_lines.branch_id` does. A customer served by
 * two branches is one customer.
 *
 * The address and contact columns deliberately mirror `companies`, so one renderer and one form
 * component serve both. Where they differ is the billing terms below, which a company does not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Denormalised from `company_id`, as everywhere else in the platform: it costs 16 bytes a
            // row and buys a uniform index prefix and a uniform RLS policy on every table.
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Restrict rather than cascade: losing a branch must not take its customers with it.
            // Nulled instead, because the branch is a dimension and the customer survives it.
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // ── Identity ────────────────────────────────────────────────────
            // A code, not a gapless number. No tax authority audits customer codes for completeness,
            // so the row lock gapless numbering costs would buy nothing. Unique per company,
            // case-insensitively, via the expression index below.
            $table->string('code', 32);
            $table->string('name');
            $table->string('legal_name')->nullable();

            // ── Statutory registrations (Sri Lanka first, generic shape) ────
            $table->string('tax_identification_number', 64)->nullable();
            $table->string('vat_registration_number', 64)->nullable();
            $table->boolean('is_vat_registered')->default(false);

            // ── Contact ─────────────────────────────────────────────────────
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();

            // ── Billing address ─────────────────────────────────────────────
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 96)->nullable();
            $table->string('district', 96)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->char('country_code', 2)->nullable();

            // ── Billing terms ───────────────────────────────────────────────
            // Days from invoice date to due date. Zero means due on receipt, which is a real term and
            // not a missing value — hence a default rather than nullable.
            $table->unsignedSmallInteger('payment_terms_days')->default(30);

            // NULL means no limit, which is different from zero — zero would mean this customer may
            // not be invoiced on credit at all.
            $table->decimal('credit_limit', 19, 4)->nullable();

            // The receivable account this customer's invoices debit. Nullable: most customers use the
            // company's system AR account, and only a business that segments receivables sets it.
            // Restrict on delete, because a customer pointing at a deleted account would post nowhere.
            $table->foreignUuid('receivable_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

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
        // index `accounts_company_code_unique` uses, and for the same reason: "ABC" and "abc" are one
        // customer to everyone except a naive unique constraint.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX customers_company_code_unique
                ON customers (company_id, upper(code))
                WHERE deleted_at IS NULL
        SQL);

        // Type-ahead without a table scan, matching how `users` supports its picker.
        DB::statement('CREATE INDEX customers_name_trgm ON customers USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX customers_code_trgm ON customers USING gin (code gin_trgm_ops)');

        $statuses = implode("', '", ['active', 'inactive', 'archived']);
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('{$statuses}'))");

        // The archive timestamp and the status cannot disagree. Phase 2 learned this the hard way on
        // `fiscal_periods`, where a mass update moved `status` and left `closed_at` behind and the
        // constraint caught it — which is the constraint doing its job, not being awkward.
        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_archived_check
                CHECK ((status = 'archived') = (archived_at IS NOT NULL))
        SQL);

        // A negative limit is meaningless. Zero is not: it means no credit at all.
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_check CHECK (credit_limit IS NULL OR credit_limit >= 0)');

        // A VAT number without registration, or registration without a number, is one of them being
        // wrong. `companies` states the same rule about its own registrations.
        DB::statement(<<<'SQL'
            ALTER TABLE customers
                ADD CONSTRAINT customers_vat_registration_check
                CHECK (NOT is_vat_registered OR vat_registration_number IS NOT NULL)
        SQL);

        DB::statement("COMMENT ON TABLE customers IS 'Parties a company invoices. Owned by a company, because the receivable balance and credit terms belong to one set of books.'");
        DB::statement("COMMENT ON COLUMN customers.credit_limit IS 'NULL means unlimited. Zero means no credit — a different statement.'");
        DB::statement("COMMENT ON COLUMN customers.branch_id IS 'Advisory dimension. Narrows a report; does not partition the data.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
