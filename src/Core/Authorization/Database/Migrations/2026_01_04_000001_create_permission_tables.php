<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role based access control, built on spatie/laravel-permission in "teams" mode
 * where the team key is `tenant_id`.
 *
 * The distinction the schema encodes:
 *
 *   permissions  A capability the *software* offers ("invoice.approve"). Global,
 *                seeded from code, never editable by a customer.
 *   roles        A bundle of capabilities a *customer* defines for its own
 *                organisation. Tenant scoped, editable, except for the system
 *                roles every tenant is provisioned with.
 *
 * Keeping permissions global is what makes the catalogue auditable: a security
 * reviewer can enumerate every capability in the product from one table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('guard_name', 32)->default('web');

            // Presentation metadata for the permission matrix UI.
            $table->string('module', 64);
            $table->string('resource', 64);
            $table->string('action', 48);
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Capabilities that can move money or alter posted history are
            // flagged so the UI can require step-up authentication and the
            // seeder can refuse to grant them to a default role.
            $table->boolean('is_sensitive')->default(false);

            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
            $table->index(['module', 'resource', 'sort_order']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Spatie's configured team foreign key. NULL means a template role
            // owned by the platform, cloned into a tenant at provisioning.
            $table->foreignUuid('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('guard_name', 32)->default('web');
            $table->string('label');
            $table->text('description')->nullable();

            // System roles are provisioned by the platform and cannot be renamed
            // or deleted by a tenant administrator, only re-scoped.
            $table->boolean('is_system')->default(false);
            // Exactly one role per tenant may hold every permission implicitly.
            $table->boolean('is_owner')->default(false);
            $table->unsignedSmallInteger('level')->default(50);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'name', 'guard_name']);
            $table->index(['tenant_id', 'level']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->foreignUuid('permission_id')
                ->constrained('permissions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('model_type');
            $table->uuid('model_uuid');
            $table->uuid('tenant_id')->nullable();

            $table->index(['model_uuid', 'model_type'], 'model_has_permissions_model_index');
            $table->primary(
                ['tenant_id', 'permission_id', 'model_uuid', 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignUuid('role_id')
                ->constrained('roles')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('model_type');
            $table->uuid('model_uuid');
            $table->uuid('tenant_id')->nullable();

            $table->index(['model_uuid', 'model_type'], 'model_has_roles_model_index');
            $table->primary(
                ['tenant_id', 'role_id', 'model_uuid', 'model_type'],
                'model_has_roles_role_model_type_primary'
            );
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignUuid('permission_id')
                ->constrained('permissions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('role_id')
                ->constrained('roles')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        // A permission name is derived from its parts; keep them in agreement so
        // the catalogue cannot drift from the strings used in code and policies.
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_name_matches_parts_check CHECK (name = module || '.' || resource || '.' || action)");

        // At most one owner role per tenant.
        DB::statement('CREATE UNIQUE INDEX roles_one_owner_per_tenant ON roles (tenant_id) WHERE is_owner');

        DB::statement('COMMENT ON TABLE permissions IS \'Global capability catalogue. Seeded from code; never tenant editable.\'');
        DB::statement('COMMENT ON TABLE roles IS \'Tenant-scoped bundles of permissions. tenant_id NULL denotes a platform template.\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
