<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previous password hashes, retained to block reuse.
 *
 * Only the hash is kept, never the plaintext, and the retained count is bounded
 * by config('asids.auth.password.history') — older rows are pruned when a new
 * password is set, so this table cannot grow without limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('password_hash');
            $table->timestampTz('created_at')->useCurrent();

            // Reuse checks read the N most recent hashes for one user.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};
