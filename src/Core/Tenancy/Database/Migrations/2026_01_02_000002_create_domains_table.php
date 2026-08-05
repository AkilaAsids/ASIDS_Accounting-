<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hostnames that resolve to a tenant.
 *
 * Every tenant gets `{slug}.{central_domain}` at provisioning time. Customers on
 * higher plans may additionally point their own hostname
 * (erp.acme.lk) at the platform, which is what makes this a table rather than a
 * computed value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('domain', 255);

            // Exactly one domain per tenant is canonical; the others 301 to it so
            // sessions, cookies and signed URLs have a single origin.
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_custom')->default(false);

            // Custom domains are unverified until the customer's DNS is proven.
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_token', 64)->nullable();

            $table->timestampsTz();

            $table->index('tenant_id');
        });

        // Hostnames are case-insensitive per RFC 4343.
        DB::statement('CREATE UNIQUE INDEX domains_domain_unique ON domains (lower(domain))');

        // At most one primary hostname per tenant, enforced by the database
        // rather than by a service that could be bypassed by a bulk import.
        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_tenant ON domains (tenant_id) WHERE is_primary');

        DB::statement("ALTER TABLE domains ADD CONSTRAINT domains_shape_check CHECK (domain ~ '^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
