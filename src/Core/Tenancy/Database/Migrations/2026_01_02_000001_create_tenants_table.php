<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant is the subscription boundary: one paying customer of ASIDS ERP
 * Cloud. A tenant owns one or more companies (legal entities), and every
 * tenant-scoped row in the platform carries this table's primary key.
 *
 * `data` is stancl/tenancy's overflow column for attributes that do not warrant
 * a schema change (feature flags, onboarding progress). Anything that is
 * queried, reported on or constrained gets a real column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Identity
            $table->string('name');
            $table->string('slug', 63)->unique();
            $table->string('legal_name')->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->string('tax_identification_number', 64)->nullable();

            // Lifecycle
            $table->string('status', 24)->default('provisioning');
            $table->string('plan_code', 48)->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('subscription_ends_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestampTz('provisioned_at')->nullable();

            // Regional defaults inherited by companies created inside the tenant.
            $table->char('country_code', 2)->default('LK');
            $table->char('currency_code', 3)->default('LKR');
            $table->string('timezone', 64)->default('Asia/Colombo');
            $table->string('locale', 8)->default('en');

            // Primary contact, used for billing and security notifications.
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();

            // Hard limits, overriding config('asids.limits') per contract.
            $table->unsignedInteger('max_companies')->nullable();
            $table->unsignedInteger('max_users')->nullable();

            $table->jsonb('data')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index(['status', 'created_at']);
            $table->index('plan_code');
        });

        // Emails are compared case-insensitively everywhere in the platform.
        DB::statement('CREATE UNIQUE INDEX tenants_contact_email_unique ON tenants (lower(contact_email)) WHERE contact_email IS NOT NULL AND deleted_at IS NULL');

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('provisioning', 'active', 'suspended', 'cancelled'))");

        // A slug becomes a DNS label; enforce the RFC 1123 shape in the database
        // so no code path can create an unroutable tenant.
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_shape_check CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$')");

        DB::statement('COMMENT ON TABLE tenants IS \'Subscription boundary. Root of every tenant-scoped relation in the platform.\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
