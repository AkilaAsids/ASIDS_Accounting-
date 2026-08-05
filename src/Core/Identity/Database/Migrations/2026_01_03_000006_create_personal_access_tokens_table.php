<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum personal access tokens, extended for an enterprise audit trail.
 *
 * Beyond Sanctum's own columns this table records who issued the token, from
 * where it was created, when it expires and — critically — the fact of
 * revocation as a distinct state from deletion, so "this integration was turned
 * off on the 4th" remains answerable after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();

            // Sanctum's polymorphic owner. UUID keyed to match `users`.
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');

            $table->string('name');
            $table->char('token', 64)->unique();
            $table->jsonb('abilities')->nullable();

            $table->string('description')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_ip', 45)->nullable();

            // Optional network restriction: a comma-free JSON array of CIDR
            // blocks the token may be used from.
            $table->jsonb('allowed_ip_ranges')->nullable();

            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();

            $table->timestampsTz();

            $table->index(['tokenable_type', 'tokenable_id']);
            // Listing a user's live tokens, and the nightly expiry sweep.
            $table->index(['tokenable_id', 'revoked_at', 'expires_at']);
        });

        DB::statement('COMMENT ON COLUMN personal_access_tokens.token IS \'SHA-256 of the plaintext token. The plaintext is shown once, at creation, and never stored.\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
