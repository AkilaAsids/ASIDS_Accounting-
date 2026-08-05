<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use two factor recovery codes.
 *
 * These are stored as individual rows rather than an encrypted JSON blob on the
 * user for two reasons a regulated customer will ask about:
 *
 *   1. Each code is hashed independently, so the set cannot be recovered even
 *      with the application key.
 *   2. Consumption is recorded with a timestamp and an IP address, which makes
 *      "someone used my recovery code" an answerable question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // SHA-256 of the plaintext code. Codes are high entropy random
            // strings, so a fast hash is appropriate and a slow one (bcrypt)
            // would make verifying eight codes needlessly expensive.
            $table->char('code_hash', 64);

            $table->timestampTz('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'code_hash']);
            // Verification looks up the unused codes for one user.
            $table->index(['user_id', 'used_at']);
        });

        // An unused code cannot carry the IP address of a consumption that never
        // happened.
        DB::statement('ALTER TABLE two_factor_recovery_codes ADD CONSTRAINT tfrc_unused_has_no_ip_check CHECK (used_at IS NOT NULL OR used_ip IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_recovery_codes');
    }
};
