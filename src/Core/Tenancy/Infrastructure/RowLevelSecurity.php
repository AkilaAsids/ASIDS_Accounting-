<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Controlled, temporary suspension of PostgreSQL row level security.
 *
 * Three callers legitimately need to read or write across tenants: schema
 * migrations, seeders, and platform operations such as retention pruning and the
 * back office tenant list. Everything else must not.
 *
 * The suspension is deliberately awkward to invoke and trivially greppable
 * (`RowLevelSecurity::bypass`) so that its appearance in a diff draws attention. It
 * is also strictly scoped: the setting is restored in a `finally` block, so an
 * exception inside the callback cannot leave a connection with protection
 * disabled for the next request that borrows it from the pool.
 */
final class RowLevelSecurity
{
    private const string BYPASS_SETTING = 'asids.bypass_rls';

    private static int $depth = 0;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function bypass(Closure $callback): mixed
    {
        if (self::$depth === 0) {
            self::set('on');
        }

        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;

            if (self::$depth === 0) {
                self::set('off');
            }
        }
    }

    public static function isBypassed(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Reports whether the policies are actually in force for the connecting role.
     *
     * Three independent conditions must all hold, and missing any one of them disables tenant
     * isolation at the database with no error anywhere:
     *
     *   1. The role is not a SUPERUSER. **Superusers bypass row level security unconditionally,
     *      even on a FORCED table** — this is the condition that is easiest to get wrong,
     *      because a local or CI database commonly connects as one.
     *   2. The role does not hold BYPASSRLS.
     *   3. The table has RLS enabled, and either the role does not own it or it is FORCED.
     *
     * `asids:security-check` gates releases on this, so a false positive here is worse than no
     * check at all.
     */
    public static function isEnforced(string $table = 'companies'): bool
    {
        /** @var object{is_superuser: bool, can_bypass: bool}|null $role */
        $role = DB::selectOne(
            'SELECT rolsuper AS is_superuser, rolbypassrls AS can_bypass
             FROM pg_roles WHERE rolname = current_user'
        );

        if ($role === null || (bool) $role->is_superuser || (bool) $role->can_bypass) {
            return false;
        }

        /** @var object{enabled: bool, forced: bool, is_owner: bool}|null $relation */
        $relation = DB::selectOne(
            'SELECT relrowsecurity AS enabled,
                    relforcerowsecurity AS forced,
                    pg_get_userbyid(relowner) = current_user AS is_owner
             FROM pg_class WHERE relname = ?',
            [$table]
        );

        if ($relation === null || ! (bool) $relation->enabled) {
            return false;
        }

        return (bool) $relation->forced || ! (bool) $relation->is_owner;
    }

    private static function set(string $value): void
    {
        DB::statement('SELECT set_config(?, ?, false)', [self::BYPASS_SETTING, $value]);
    }
}
