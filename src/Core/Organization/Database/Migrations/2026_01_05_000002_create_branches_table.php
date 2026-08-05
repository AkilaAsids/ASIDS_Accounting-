<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A branch is an operating location within a company: a shop, a warehouse, a
 * regional office. Branches are a *dimension* on transactions, not a separate
 * set of books — a company's trial balance is the sum across its branches.
 *
 * `tenant_id` is carried denormalised alongside `company_id` so that row level
 * security and every tenant-scoped index work uniformly on every table without
 * a join back to `companies`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('code', 24);

            // The primary branch is where transactions land when a document does
            // not name one. Every company has exactly one.
            $table->boolean('is_primary')->default(false);

            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 96)->nullable();
            $table->string('district', 96)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->string('status', 24)->default('active');
            $table->timestampTz('archived_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'company_id', 'status']);
            $table->index(['company_id', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX branches_company_code_unique ON branches (company_id, upper(code)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX branches_one_primary_per_company ON branches (company_id) WHERE is_primary AND deleted_at IS NULL');

        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_archived_consistency_check CHECK ((status = \'archived\') = (archived_at IS NOT NULL))');
        // A primary branch may not be archived: it is the fallback for posting.
        DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_primary_is_active_check CHECK (NOT is_primary OR status = \'active\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
