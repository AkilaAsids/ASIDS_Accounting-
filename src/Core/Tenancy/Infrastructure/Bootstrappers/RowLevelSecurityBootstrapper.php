<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure\Bootstrappers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Publishes the active tenant to PostgreSQL so the row level security policies created in
 * `2026_01_08_000001_enable_row_level_security.php` have something to compare against.
 *
 * `SET` (session scope) rather than `SET LOCAL` (transaction scope) is used deliberately:
 * tenancy is initialised once per request, outside any transaction, and a transaction-scoped
 * value would evaporate before the first query. The matching `revert()` is what keeps a pooled
 * PHP-FPM connection from carrying one request's tenant into the next.
 *
 * `set_config(..., false)` is used rather than string interpolation into a `SET` statement so
 * the tenant id travels as a bound parameter and cannot be injected.
 */
final class RowLevelSecurityBootstrapper implements TenancyBootstrapper
{
    private const string TENANT_SETTING = 'asids.tenant_id';

    /** PostgreSQL: "current transaction is aborted, commands ignored until end of block". */
    private const string IN_FAILED_TRANSACTION = '25P02';

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

        // An empty string, not NULL: `current_setting(..., true)` returns '' for a reset value,
        // and the policy's NULLIF turns that into "no tenant", which is the fail-closed state.
        $this->apply('');
    }

    /**
     * Sets the session variable, tolerating one specific condition: a transaction that has
     * already failed.
     *
     * WHY THIS MATTERS MORE THAN IT LOOKS
     * -----------------------------------
     * Once a statement errors inside a PostgreSQL transaction, every subsequent command in that
     * transaction is refused with 25P02 until it is rolled back. Tenancy is reverted from a
     * `finally` block — `TenantContext::runFor()`, `runCentrally()`, the queue listener — so a
     * legitimate failure inside a transaction (a check constraint, a unique violation, an RLS
     * WITH CHECK refusal) reaches that `finally` with a dead connection. Attempting to write the
     * session variable there throws a *second* exception which replaces the first, so the caller
     * is told "current transaction is aborted" instead of "you tried to write to another
     * workspace" — and, worse, the original exception's propagation is interrupted before the
     * transaction is rolled back, leaving locks held until the connection closes.
     *
     * Swallowing 25P02 specifically is safe: no command can succeed on that connection anyway,
     * and the imminent rollback resets session state, so the value being written is moot. Any
     * other failure — a missing privilege, a dropped connection — still throws, because silently
     * failing to publish the tenant would silently disable row level security.
     */
    private function apply(string $tenantId): void
    {
        try {
            $this->database->connection()->statement(
                'SELECT set_config(?, ?, false)',
                [self::TENANT_SETTING, $tenantId]
            );
        } catch (QueryException $exception) {
            if (($exception->getCode() !== self::IN_FAILED_TRANSACTION)
                && ! str_contains($exception->getMessage(), self::IN_FAILED_TRANSACTION)) {
                throw $exception;
            }

            Log::debug('Skipped publishing the tenant: the transaction had already failed.', [
                'tenant_id' => $tenantId === '' ? null : $tenantId,
            ]);
        }
    }

    private function enabled(): bool
    {
        return (bool) config('asids.tenancy.enforce_rls', true);
    }
}
