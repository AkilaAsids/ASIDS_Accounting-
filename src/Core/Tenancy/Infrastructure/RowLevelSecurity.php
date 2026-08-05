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
     * Used by `asids:security-check` — a deployment where the application connects
     * as the table owner has RLS silently disabled unless the tables are FORCED,
     * and that is exactly the misconfiguration worth alarming on.
     */
    public static function isEnforced(string $table = 'companies'): bool
    {
        /** @var object{relrowsecurity: bool, relforcerowsecurity: bool}|null $row */
        $row = DB::selectOne(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
            [$table]
        );

        if ($row === null) {
            return false;
        }

        // Either the connecting role is not the owner (policies apply normally) or
        // the table forces them even for its owner.
        return (bool) $row->relrowsecurity
            && ((bool) $row->relforcerowsecurity || ! self::connectedAsOwner($table));
    }

    private static function connectedAsOwner(string $table): bool
    {
        /** @var object{is_owner: bool}|null $row */
        $row = DB::selectOne(
            'SELECT pg_get_userbyid(relowner) = current_user AS is_owner FROM pg_class WHERE relname = ?',
            [$table]
        );

        return (bool) ($row->is_owner ?? false);
    }

    private static function set(string $value): void
    {
        DB::statement('SELECT set_config(?, ?, false)', [self::BYPASS_SETTING, $value]);
    }
}
