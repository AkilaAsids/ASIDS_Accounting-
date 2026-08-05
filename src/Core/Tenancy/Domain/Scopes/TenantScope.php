<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Scopes;

use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-scoped model to the active tenant.
 *
 * This is the *primary* isolation mechanism; PostgreSQL row level security is the
 * backstop for the cases this cannot reach (raw queries, `withoutGlobalScopes()`).
 *
 * Behaviour when no tenant is active is the important design decision. It fails
 * closed: with no tenant context, only rows whose `tenant_id` is NULL are visible.
 * The tempting alternative — return everything when no tenant is set — turns every
 * console command, queued job and forgotten middleware into a cross-tenant leak.
 * Code that genuinely needs to read across tenants must say so explicitly through
 * `TenantContext::runCentrally()` or the model's `withoutTenantScope()` helper,
 * which is greppable in review.
 */
final readonly class TenantScope implements Scope
{
    public const string IDENTIFIER = 'asids_tenant';

    public function __construct(private TenantContext $context) {}

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn(
            method_exists($model, 'tenantColumn') ? $model->tenantColumn() : 'tenant_id'
        );

        $tenantId = $this->context->id();

        if ($tenantId === null) {
            $builder->whereNull($column);

            return;
        }

        // A few tables legitimately hold platform-owned rows with a NULL tenant —
        // role templates and system-scope settings — which a tenant should see
        // alongside its own. That inclusion is opt-in per model rather than
        // universal: applying it to `users` would put ASIDS staff accounts into
        // every customer's user list.
        $includesPlatformRows = method_exists($model, 'tenantScopeIncludesPlatformRows')
            && $model->tenantScopeIncludesPlatformRows();

        if (! $includesPlatformRows) {
            $builder->where($column, $tenantId);

            return;
        }

        $builder->where(static function (Builder $query) use ($column, $tenantId): void {
            $query->where($column, $tenantId)->orWhereNull($column);
        });
    }
}
