<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which users may act inside which companies, and where they land by default.
 *
 * Membership is deliberately separate from role assignment. A role answers
 * "what may this person do?"; membership answers "whose books may they touch?".
 * Both must pass. Without this table a tenant administrator could not hire a
 * bookkeeper for one of five group companies without exposing the other four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Optional narrowing to a single branch. NULL means every branch of
            // the company.
            $table->foreignUuid('branch_id')
                ->nullable()
                ->constrained('branches')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->boolean('is_default')->default(false);
            $table->timestampTz('joined_at')->useCurrent();
            $table->foreignUuid('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->unique(['company_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
        });

        // One default company per user.
        DB::statement('CREATE UNIQUE INDEX company_memberships_one_default_per_user ON company_memberships (user_id) WHERE is_default AND revoked_at IS NULL');

        // A revoked membership can never be the default landing company.
        DB::statement('ALTER TABLE company_memberships ADD CONSTRAINT company_memberships_revoked_not_default_check CHECK (revoked_at IS NULL OR NOT is_default)');

        DB::statement('COMMENT ON TABLE company_memberships IS \'Company-level data access. Complements, never replaces, role based permissions.\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_memberships');
    }
};
