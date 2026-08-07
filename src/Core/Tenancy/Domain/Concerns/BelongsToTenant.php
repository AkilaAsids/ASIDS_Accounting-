<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Concerns;

use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Exceptions\CrossTenantWriteAttempted;
use Asids\Core\Tenancy\Domain\Exceptions\NoActiveTenant;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Domain\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-scoped model.
 *
 * Provides three things, all of which have to be automatic to be trustworthy:
 *
 *   1. Reads are filtered to the active tenant (TenantScope).
 *   2. Writes are stamped with the active tenant, so no caller can forget.
 *   3. A write that would cross tenants is refused before it reaches the
 *      database, giving a clear domain error instead of an RLS policy violation
 *      that surfaces as an opaque SQL error.
 *
 * @property string|null $tenant_id
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // Registered under an explicit identifier so `withoutGlobalScope()` has a
        // stable name to remove, independent of the scope's class name.
        static::addGlobalScope(TenantScope::IDENTIFIER, app(TenantScope::class));

        static::creating(static function (self $model): void {
            $column = $model->tenantColumn();

            if ($model->getAttribute($column) !== null) {
                $model->assertWritableInCurrentTenant();

                return;
            }

            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                // Models that may legitimately exist outside a tenant (platform
                // staff users, role templates) declare it; everything else is a
                // programming error worth failing loudly for, because the
                // alternative is a globally visible row.
                if ($model->tenantIsOptional()) {
                    return;
                }

                throw new NoActiveTenant;
            }

            $model->setAttribute($column, $tenantId);
        });

        // Re-parenting a record to another tenant is never a legitimate update.
        static::updating(static function (self $model): void {
            $column = $model->tenantColumn();

            if ($model->isDirty($column) && $model->getOriginal($column) !== null) {
                throw new CrossTenantWriteAttempted(
                    model: static::class,
                    from: (string) $model->getOriginal($column),
                    to: (string) $model->getAttribute($column),
                );
            }

            $model->assertWritableInCurrentTenant();
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, $this->tenantColumn());
    }

    public function tenantColumn(): string
    {
        return 'tenant_id';
    }

    /**
     * Whether a NULL tenant is meaningful for this model. Overridden by User
     * (platform staff) and Role (platform templates).
     */
    public function tenantIsOptional(): bool
    {
        return false;
    }

    /**
     * Whether reads inside a tenant should also return platform-owned rows.
     */
    public function tenantScopeIncludesPlatformRows(): bool
    {
        return false;
    }

    /**
     * Escape hatch for genuinely cross-tenant reads (platform back office,
     * retention sweeps). Named verbosely so it stands out in a code review, and
     * paired with a row level security bypass because removing the Eloquent scope
     * alone would now hit the database policy instead.
     *
     * `self` rather than `static`: every model using this trait is final, so the two are the same
     * class, and `self` keeps the builder's model parameter — which is invariant — identical on
     * both sides of the return.
     *
     * @return Builder<self>
     */
    public static function acrossAllTenants(): Builder
    {
        return self::query()->withoutGlobalScope(TenantScope::IDENTIFIER);
    }

    /**
     * Guards against writing a row belonging to a different tenant than the one
     * currently active — the mirror image of the read scope.
     */
    protected function assertWritableInCurrentTenant(): void
    {
        $column = $this->tenantColumn();
        $rowTenant = $this->getAttribute($column);

        if ($rowTenant === null) {
            return;
        }

        $activeTenant = app(TenantContext::class)->id();

        // No active tenant means a central or console context, which is permitted
        // to write on behalf of a tenant it names explicitly (provisioning,
        // migrations, administrative repair).
        if ($activeTenant === null || $activeTenant === $rowTenant) {
            return;
        }

        throw new CrossTenantWriteAttempted(
            model: static::class,
            from: $activeTenant,
            to: (string) $rowTenant,
        );
    }
}
