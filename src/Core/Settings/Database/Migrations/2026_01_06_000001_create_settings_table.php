<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchical settings store.
 *
 * A setting is resolved by walking the most specific scope outwards:
 *
 *     user → company → tenant → system → the definition's default in code
 *
 * Only *overrides* are persisted. A tenant that never changes a value stores no
 * row for it, which is what keeps this table small at a hundred thousand tenants
 * instead of holding a hundred thousand copies of every default.
 *
 * The catalogue of valid keys, their types, defaults and validation lives in
 * code (`Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue`), not in a database table, so a
 * setting cannot exist without the code that understands it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // NULL tenant_id denotes a platform-wide (system scope) value.
            $table->foreignUuid('tenant_id')
                ->nullable()
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('scope', 16);
            // Points at a company or a user depending on `scope`; NULL for the
            // tenant and system scopes. No foreign key: the target table varies,
            // and orphan cleanup is handled by the owning module's observers.
            $table->uuid('scope_id')->nullable();

            $table->string('key', 160);
            $table->string('type', 24);
            // Stored as JSONB so a value keeps its shape (a boolean stays a
            // boolean, a list stays a list) without a stringly-typed round trip.
            $table->jsonb('value');
            $table->boolean('is_encrypted')->default(false);

            $table->foreignUuid('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // The hot path: "give me every override for this scope", which the
            // resolver issues once per request and caches.
            $table->index(['tenant_id', 'scope', 'scope_id']);
            $table->index(['key']);
        });

        // One value per (tenant, scope, scope target, key). Expression index
        // because NULLs are distinct in a plain unique index, which would let two
        // system-scope rows exist for the same key.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settings_scope_key_unique ON settings (
                COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid),
                scope,
                COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'::uuid),
                key
            )
        SQL);

        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_scope_check CHECK (scope IN ('system', 'tenant', 'company', 'user'))");

        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_type_check CHECK (type IN ('string', 'text', 'integer', 'float', 'boolean', 'array', 'json', 'date', 'datetime', 'time'))");

        // Scope and its target must agree: system/tenant carry no target,
        // company/user must name one.
        DB::statement(<<<'SQL'
            ALTER TABLE settings ADD CONSTRAINT settings_scope_target_check CHECK (
                (scope IN ('system', 'tenant') AND scope_id IS NULL)
                OR (scope IN ('company', 'user') AND scope_id IS NOT NULL)
            )
        SQL);

        // System scope is platform-wide and therefore has no tenant; every other
        // scope must be inside one.
        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_system_has_no_tenant_check CHECK ((scope = 'system') = (tenant_id IS NULL))");

        DB::statement('COMMENT ON TABLE settings IS \'Sparse override store. Absence of a row means "inherit".\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
