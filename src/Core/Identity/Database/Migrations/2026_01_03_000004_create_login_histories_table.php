<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every authentication attempt, successful or not.
 *
 * `user_id` is nullable on purpose: a failed attempt against an address that
 * does not exist is exactly the signal a security team needs, and discarding it
 * would blind credential-stuffing detection. `email_attempted` is stored
 * separately for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->foreignUuid('device_id')->nullable();

            $table->string('email_attempted');
            $table->string('outcome', 32);
            $table->string('failure_reason', 64)->nullable();
            $table->string('guard', 32)->default('web');
            $table->string('channel', 24)->default('web');

            // Request provenance.
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('city', 96)->nullable();

            $table->boolean('two_factor_used')->default(false);
            $table->string('two_factor_method', 24)->nullable();

            $table->string('session_id')->nullable();
            $table->timestampTz('logged_out_at')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // "Recent activity" for one user, newest first.
            $table->index(['user_id', 'created_at']);
            // Brute-force analysis by source address within a time window.
            $table->index(['ip_address', 'created_at']);
            $table->index(['outcome', 'created_at']);
        });

        DB::statement("ALTER TABLE login_histories ADD CONSTRAINT login_histories_outcome_check CHECK (outcome IN ('succeeded', 'failed', 'locked_out', 'two_factor_required', 'two_factor_failed', 'password_expired', 'account_inactive'))");
        DB::statement("ALTER TABLE login_histories ADD CONSTRAINT login_histories_channel_check CHECK (channel IN ('web', 'api', 'mobile', 'sso'))");
        DB::statement("ALTER TABLE login_histories ADD CONSTRAINT login_histories_two_factor_method_check CHECK (two_factor_method IS NULL OR two_factor_method IN ('totp', 'recovery_code'))");

        DB::statement('COMMENT ON TABLE login_histories IS \'Append-only authentication audit. Retention is governed by config(asids.audit.retention_days).\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
