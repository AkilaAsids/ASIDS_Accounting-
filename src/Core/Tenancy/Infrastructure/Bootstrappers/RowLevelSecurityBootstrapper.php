<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure\Bootstrappers;

use Illuminate\Database\DatabaseManager;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;

/**
 * Publishes the active tenant to PostgreSQL so the row level security policies
 * created in `2026_01_08_000001_enable_row_level_security.php` have something to
 * compare against.
 *
 * `SET` (session scope) rather than `SET LOCAL` (transaction scope) is used
 * deliberately: tenancy is initialised once per request, outside any transaction,
 * and a transaction-scoped value would evaporate before the first query. The
 * matching `revert()` is what keeps a pooled PHP-FPM connection from carrying one
 * request's tenant into the next.
 *
 * `set_config(..., false)` is used rather than string interpolation into a `SET`
 * statement so the tenant id travels as a bound parameter and cannot be injected.
 */
final class RowLevelSecurityBootstrapper implements TenancyBootstrapper
{
    private const string TENANT_SETTING = 'asids.tenant_id';

    public function __construct(private readonly DatabaseManager $database) {}

    public function bootstrap(Tenant $tenant): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->apply((string) $tenant->getTenantKey());
    }

    public function revert(): void
    {
        if (! $this->enabled()) {
            return;
        }

        // An empty string, not NULL: `current_setting(..., true)` returns '' for a
        // reset value, and the policy's NULLIF turns that into "no tenant", which
        // is the fail-closed state.
        $this->apply('');
    }

    private function apply(string $tenantId): void
    {
        $connection = $this->database->connection();

        $connection->statement(
            'SELECT set_config(?, ?, false)',
            [self::TENANT_SETTING, $tenantId]
        );
    }

    private function enabled(): bool
    {
        return (bool) config('asids.tenancy.enforce_rls', true);
    }
}
