<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The books an entry is recorded in — general, sales, purchases, cash.
 *
 * A journal is an organising device, not a separate ledger: a company's trial balance is the sum
 * across all of them. It exists so that a later module can post into its own book without its
 * entries being indistinguishable from a bookkeeper's manual corrections, which is what makes
 * "show me every manual adjustment this year" a question with an answer.
 *
 * Phase 2 creates one general journal per company. Sales and purchases arrive with their phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('code', 24);
            $table->string('name');
            $table->text('description')->nullable();

            // The general journal is where manual entries go and where the platform posts anything
            // without a more specific home. Exactly one per company, so a caller that needs "the
            // journal" has an unambiguous answer.
            $table->boolean('is_general')->default(false);

            // A system journal is created by the platform and cannot be deleted — later modules
            // resolve theirs by code.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index(['tenant_id', 'company_id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX journals_company_code_unique
                ON journals (company_id, lower(code))
        SQL);

        // Exactly one general journal per company. A partial unique index rather than a check, for
        // the same reason `companies.is_default` uses one: the rule is about the set, not the row.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX journals_company_general_unique
                ON journals (company_id)
                WHERE is_general
        SQL);

        DB::statement("COMMENT ON TABLE journals IS 'Books entries are recorded in. Organising device only — the trial balance is the sum across all journals.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
