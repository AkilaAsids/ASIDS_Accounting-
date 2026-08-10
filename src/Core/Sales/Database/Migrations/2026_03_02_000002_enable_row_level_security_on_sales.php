<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant isolation for the Sales module, in the database.
 *
 * The same shape as the Accounting module's policy migration, reusing `asids_current_tenant_id()`
 * and `asids_rls_bypassed()` from Phase 1 rather than redefining them — one definition of "the active
 * tenant" and one bypass switch for the whole platform.
 *
 * FORCE is what makes the policy apply to the table's owner as well. Without it a role that owns the
 * table runs with protection off, and CI proved exactly how that fails: the suite ran as a superuser
 * for its first three runs, `RowLevelSecurityTest` skipped its eleven assertions rather than passing
 * vacuously, and tenant isolation was verified by nothing at all.
 */
return new class extends Migration
{
    /**
     * Every Sales table. All carry a non-null `tenant_id`.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'customers',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                    FOR ALL
                    USING (asids_rls_bypassed() OR tenant_id = asids_current_tenant_id())
                    WITH CHECK (asids_rls_bypassed() OR tenant_id = asids_current_tenant_id())
            SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
