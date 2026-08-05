<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform users.
 *
 * `tenant_id` is nullable for exactly one reason: ASIDS staff operating the
 * platform itself (support, billing, incident response) are users without a
 * tenant. Every such account must have `is_platform_admin = true`, which is
 * enforced by a check constraint below rather than by convention.
 *
 * E-mail uniqueness is scoped to the tenant. The same person may legitimately be
 * a user of two different customers of ASIDS — for example an external
 * accountant serving several SMEs — and must not be forced to hold one identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // ── Identity ───────────────────────────────────────────────────
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('employee_number', 64)->nullable();
            $table->string('avatar_path')->nullable();

            // ── Credentials ────────────────────────────────────────────────
            $table->string('password')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->rememberToken();

            // ── Two factor authentication ──────────────────────────────────
            // The secret is encrypted at the application layer before it ever
            // reaches PostgreSQL, so a database dump alone cannot mint codes.
            $table->text('two_factor_secret')->nullable();
            $table->timestampTz('two_factor_enrolled_at')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();

            // ── Lifecycle ──────────────────────────────────────────────────
            $table->string('status', 24)->default('pending_invitation');
            $table->boolean('is_platform_admin')->default(false);
            $table->foreignUuid('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('invitation_accepted_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->string('deactivation_reason')->nullable();

            // ── Preferences ────────────────────────────────────────────────
            $table->string('locale', 8)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('theme', 16)->default('system');
            $table->foreignUuid('default_company_id')->nullable();

            // ── Security telemetry ─────────────────────────────────────────
            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Nearly every tenant-scoped query filters on tenant_id first.
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
            $table->index('last_activity_at');
        });

        // Case-insensitive, tenant-scoped uniqueness. Soft-deleted rows are
        // excluded so an address can be reused after an account is removed.
        DB::statement('CREATE UNIQUE INDEX users_tenant_email_unique ON users (tenant_id, lower(email)) WHERE deleted_at IS NULL');

        // Platform staff have no tenant, so the index above cannot constrain
        // them (NULLs are distinct); this partial index covers that case.
        DB::statement('CREATE UNIQUE INDEX users_platform_email_unique ON users (lower(email)) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        DB::statement('CREATE UNIQUE INDEX users_tenant_employee_number_unique ON users (tenant_id, employee_number) WHERE employee_number IS NOT NULL AND deleted_at IS NULL');

        // Trigram index powering the "search users" picker without a full scan.
        DB::statement('CREATE INDEX users_name_trgm_index ON users USING gin ((first_name || \' \' || coalesce(last_name, \'\')) gin_trgm_ops)');
        DB::statement('CREATE INDEX users_email_trgm_index ON users USING gin (lower(email) gin_trgm_ops)');

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('pending_invitation', 'active', 'suspended', 'deactivated'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_theme_check CHECK (theme IN ('system', 'light', 'dark'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_email_shape_check CHECK (email ~* '^[^@[:space:]]+@[^@[:space:]]+\\.[a-z]{2,}$')");

        // A user either belongs to a tenant, or is platform staff. Never neither,
        // never both.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_tenant_or_platform_check CHECK ((tenant_id IS NULL) = is_platform_admin)');

        // Two factor cannot be confirmed before it was enrolled.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_two_factor_order_check CHECK (two_factor_confirmed_at IS NULL OR two_factor_enrolled_at IS NOT NULL)');

        DB::statement('COMMENT ON COLUMN users.two_factor_secret IS \'Application-layer encrypted TOTP shared secret.\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
