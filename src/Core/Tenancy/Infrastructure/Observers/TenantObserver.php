<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure\Observers;

use Asids\Core\Tenancy\Application\Services\TenantResolver;
use Asids\Core\Tenancy\Domain\Models\Tenant;

/**
 * Keeps the tenant resolution cache honest.
 *
 * Resolution is cached on the hot path of every request, so the one thing that
 * must never go stale is a status change: a suspended workspace that keeps serving
 * for five more minutes is a billing and abuse problem, not a cosmetic one.
 */
final readonly class TenantObserver
{
    public function __construct(private TenantResolver $resolver) {}

    public function saved(Tenant $tenant): void
    {
        // `wasChanged` rather than `isDirty`: by the time `saved` fires the model is
        // clean, and only the columns that resolution depends upon warrant a flush.
        if ($tenant->wasChanged(['slug', 'status', 'deleted_at'])) {
            $this->resolver->forget($tenant);
        }
    }

    public function deleted(Tenant $tenant): void
    {
        $this->resolver->forget($tenant);
    }

    public function restored(Tenant $tenant): void
    {
        $this->resolver->forget($tenant);
    }
}
