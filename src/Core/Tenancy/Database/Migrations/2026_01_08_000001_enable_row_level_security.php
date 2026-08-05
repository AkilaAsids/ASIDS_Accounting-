<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL row level security for every tenant-scoped table.
 *
 * WHAT THIS DEFENDS AGAINST
 * -------------------------
 * A missing tenant filter in application code. The Eloquent global scope added by
 * `BelongsToTenant` is the primary mechanism; it is also the mechanism most
 * easily defeated by a raw query, a `withoutGlobalScopes()` call added during
 * debugging, or a relationship traversed from an unscoped model. RLS makes each
 * of those fail closed at the database instead of returning another customer's
 * ledger.
 *
 * WHAT THIS DOES NOT DEFEND AGAINST
 * ---------------------------------
 * A fully compromised application credential. The tenant is communicated to
 * PostgreSQL through a session variable that the application role sets itself, so
 * an attacker executing arbitrary SQL as that role can also set the variable.
 * This is deliberate and is the standard trade-off for single-database tenancy:
 * RLS here is a guard against *our* bugs, not a substitute for protecting the
 * credential. Customers requiring cryptographic separation are placed on a
 * dedicated database (see docs/adr/0001-tenancy-strategy.md).
 *
 * MECHANICS
 * ---------
 *   asids.tenant_id    UUID of the active tenant, set by
 *                      RowLevelSecurityBootstrapper on every request.
 *   asids.bypass_rls   'on' only inside migrations, seeders and explicitly
 *                      cross-tenant console commands. Never set while serving
 *                      an HTTP request.
 *
 * Policies are FORCED so that they apply even when the connecting role owns the
 * tables — otherwise a local developer connecting as the schema owner would run
 * with protection silently disabled, and the isolation tests would pass without
 * testing anything.
 */
return new class extends Migration
{
    /**
     * Tables whose `tenant_id` is NOT NULL: every row belongs to exactly one
     * tenant and nothing may be written outside the active one.
     *
     * @var list<string>
     */
    private const array STRICT_TABLES = [
        'companies',
        'branches',
        'company_memberships',
    ];

    /**
     * Tables where a NULL `tenant_id` is meaningful — platform staff accounts,
     * platform role templates, system-scope settings, audit entries for platform
     * actions. Those rows are visible from every context by design.
     *
     * @var list<string>
     */
    private const array NULLABLE_TENANT_TABLES = [
        'users',
        'two_factor_recovery_codes',
        'password_histories',
        'login_histories',
        'user_devices',
        'personal_access_tokens',
        'roles',
        'model_has_roles',
        'model_has_permissions',
        'settings',
        'audit_logs',
        'activity_logs',
        'notifications',
    ];

    public function up(): void
    {
        // Resolves the active tenant once per policy evaluation. Marked STABLE so
        // the planner may cache it within a statement; it must not be IMMUTABLE
        // because the setting changes between statements.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_current_tenant_id() RETURNS uuid
            LANGUAGE sql STABLE
            AS $$
                SELECT NULLIF(current_setting('asids.tenant_id', true), '')::uuid;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION asids_rls_bypassed() RETURNS boolean
            LANGUAGE sql STABLE
            AS $$
                SELECT COALESCE(current_setting('asids.bypass_rls', true), 'off') = 'on';
            $$;
        SQL);

        foreach (self::STRICT_TABLES as $table) {
            $this->protect(
                table: $table,
                using: 'asids_rls_bypassed() OR tenant_id = asids_current_tenant_id()',
                check: 'asids_rls_bypassed() OR tenant_id = asids_current_tenant_id()',
            );
        }

        foreach (self::NULLABLE_TENANT_TABLES as $table) {
            $this->protect(
                table: $table,
                using: 'asids_rls_bypassed() OR tenant_id IS NULL OR tenant_id = asids_current_tenant_id()',
                check: 'asids_rls_bypassed() OR tenant_id IS NULL OR tenant_id = asids_current_tenant_id()',
            );
        }
    }

    public function down(): void
    {
        foreach ([...self::STRICT_TABLES, ...self::NULLABLE_TENANT_TABLES] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('DROP FUNCTION IF EXISTS asids_rls_bypassed()');
        DB::statement('DROP FUNCTION IF EXISTS asids_current_tenant_id()');
    }

    private function protect(string $table, string $using, string $check): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        DB::statement(<<<SQL
            CREATE POLICY {$table}_tenant_isolation ON {$table}
                FOR ALL
                USING ({$using})
                WITH CHECK ({$check})
        SQL);
    }
};
