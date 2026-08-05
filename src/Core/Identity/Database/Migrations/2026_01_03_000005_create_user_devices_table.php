<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recognised devices, so a user can see "where am I signed in" and revoke a
 * device they no longer control.
 *
 * A device is identified by a hash of its stable characteristics plus a
 * long-lived signed cookie. The fingerprint is hashed rather than stored raw so
 * the table is not itself a tracking database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->char('fingerprint_hash', 64);
            $table->string('name');
            $table->string('device_type', 32)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();

            // Trust is granted only after an explicit confirmation step, and it
            // is what allows a device to skip the 2FA challenge for a period.
            $table->timestampTz('trusted_at')->nullable();
            $table->timestampTz('trust_expires_at')->nullable();

            $table->string('last_ip_address', 45)->nullable();
            $table->char('last_country_code', 2)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['user_id', 'fingerprint_hash']);
            $table->index(['user_id', 'last_seen_at']);
            $table->index(['tenant_id', 'last_seen_at']);
        });

        DB::statement('ALTER TABLE user_devices ADD CONSTRAINT user_devices_trust_window_check CHECK (trust_expires_at IS NULL OR trusted_at IS NOT NULL)');

        // A revoked device can never be trusted.
        DB::statement('ALTER TABLE user_devices ADD CONSTRAINT user_devices_revoked_not_trusted_check CHECK (revoked_at IS NULL OR trusted_at IS NULL)');

        // login_histories is created before this table, so its device reference
        // is completed here. Deleting a device must not erase the login trail,
        // hence SET NULL rather than CASCADE.
        Schema::table('login_histories', function (Blueprint $table): void {
            $table->foreign('device_id')
                ->references('id')
                ->on('user_devices')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('login_histories', function (Blueprint $table): void {
            $table->dropForeign(['device_id']);
            $table->dropIndex(['device_id', 'created_at']);
        });

        Schema::dropIfExists('user_devices');
    }
};
