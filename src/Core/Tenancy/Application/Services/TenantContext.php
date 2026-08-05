<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Application\Services;

use Asids\Core\Platform\Support\RequestContext;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Closure;
use Stancl\Tenancy\Tenancy;
use Throwable;

/**
 * The one place application code asks "which tenant am I serving?".
 *
 * Everything else in the codebase depends on this class rather than on
 * stancl/tenancy's helpers or on a facade. That indirection is worth one small
 * class: it gives a typed return value, it lets the tenancy package be replaced
 * without touching six hundred call sites, and it makes tenant switching in tests
 * a single method rather than a ritual.
 */
final readonly class TenantContext
{
    public function __construct(
        private Tenancy $tenancy,
        private RequestContext $requestContext,
    ) {}

    public function current(): ?Tenant
    {
        $tenant = $this->tenancy->tenant;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function id(): ?string
    {
        return $this->current()?->getKey();
    }

    public function has(): bool
    {
        return $this->current() !== null;
    }

    /**
     * The active tenant, or a failure. Used by code that genuinely cannot proceed
     * without one, so that the alternative — silently writing a row with a NULL
     * tenant_id, visible to everyone — is impossible.
     */
    public function require(): Tenant
    {
        $tenant = $this->current();

        if ($tenant === null) {
            throw new \Asids\Core\Tenancy\Domain\Exceptions\NoActiveTenant();
        }

        return $tenant;
    }

    public function initialize(Tenant $tenant): void
    {
        $this->tenancy->initialize($tenant);
        $this->requestContext->setTenantId($tenant->getKey());
    }

    public function end(): void
    {
        $this->tenancy->end();
        $this->requestContext->setTenantId(null);
    }

    /**
     * Run a callback as another tenant, then restore the previous context —
     * including when the callback throws, which is the case a naive
     * initialize/callback/end sequence gets wrong and which leaks one tenant's
     * context into the next request on a long-lived worker.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->current();

        $this->initialize($tenant);

        try {
            return $callback($tenant);
        } finally {
            $previous === null ? $this->end() : $this->initialize($previous);
        }
    }

    /**
     * Run a callback with no tenant context at all — for central operations such
     * as resolving a hostname or listing tenants in the back office.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runCentrally(Closure $callback): mixed
    {
        $previous = $this->current();

        if ($previous !== null) {
            $this->end();
        }

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $this->initialize($previous);
            }
        }
    }

    /**
     * Iterate every active tenant, initialising each in turn. Used by scheduled
     * work that must touch all tenants (recurring invoices, retention pruning).
     *
     * A failure in one tenant is logged and does not abort the run: a single
     * customer's bad data must not stop the nightly job for the other ninety-nine
     * thousand.
     *
     * @param  Closure(Tenant): void  $callback
     * @param  Closure(Tenant, Throwable): void|null  $onFailure
     */
    public function eachActiveTenant(Closure $callback, ?Closure $onFailure = null): void
    {
        Tenant::query()
            ->active()
            ->orderBy('id')
            ->chunkById(100, function (\Illuminate\Support\Collection $tenants) use ($callback, $onFailure): void {
                foreach ($tenants as $tenant) {
                    /** @var Tenant $tenant */
                    try {
                        $this->runFor($tenant, $callback);
                    } catch (Throwable $e) {
                        if ($onFailure === null) {
                            throw $e;
                        }

                        $onFailure($tenant, $e);
                    }
                }
            });
    }
}
