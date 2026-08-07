<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure\Bootstrappers;

use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Exceptions\TenantNotFound;
use Asids\Core\Tenancy\Domain\Models\Tenant as TenantModel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Carries the tenant across the queue boundary.
 *
 * A queued job is dispatched inside one tenant's request and executed later in a
 * worker process that has no request and no tenant. Without this, the job would
 * run with no tenant context — and because the Eloquent scope fails closed, it
 * would silently find nothing and appear to succeed. That is the worst possible
 * failure mode for a job that posts a journal entry or sends an invoice.
 *
 * The tenant key is injected into every job payload on push, and re-initialised
 * before the job runs. Payload injection (rather than serialising the whole Tenant
 * model) keeps the payload small and avoids a stale snapshot: the worker loads the
 * tenant fresh, so a suspension that happened between dispatch and execution is
 * honoured.
 */
final class QueueBootstrapper implements TenancyBootstrapper
{
    private static bool $listening = false;

    public function __construct(
        private readonly Application $app,
        private readonly QueueManager $queue,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->registerListeners();

        $tenantKey = (string) $tenant->getTenantKey();

        // Stamp the active tenant onto every job pushed from here on.
        $this->queue->createPayloadUsing(static fn (): array => ['asids_tenant_id' => $tenantKey]);
    }

    public function revert(): void
    {
        // The payload callbacks are static state on the queue manager, so they are
        // cleared to stop a subsequent central dispatch inheriting a tenant.
        $this->queue->createPayloadUsing(static fn (): array => []);
    }

    /**
     * Registered once per process, not once per tenant switch — a worker that
     * processes thousands of jobs must not accumulate thousands of listeners.
     */
    private function registerListeners(): void
    {
        if (self::$listening) {
            return;
        }

        self::$listening = true;

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            /** @var array{asids_tenant_id?: string|null} $payload */
            $payload = $event->job->payload();
            $tenantId = $payload['asids_tenant_id'] ?? null;

            $context = $this->app->make(TenantContext::class);

            if ($tenantId === null) {
                // A genuinely central job (tenant provisioning, platform
                // reporting). Ensure no previous job's tenant is still active.
                $context->end();

                return;
            }

            $tenant = TenantModel::query()->find($tenantId);

            if ($tenant === null) {
                // The tenant was deleted between dispatch and execution. Failing
                // is correct: silently running the job with no context would let
                // it write platform-visible rows.
                throw new TenantNotFound(
                    'The workspace this job belongs to no longer exists.',
                    ['tenant_id' => $tenantId],
                );
            }

            $context->initialize($tenant);
        });

        // Always tear down, whether the job succeeded or threw: the worker is
        // long-lived and the next job may belong to a different tenant.
        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(TenantContext::class)->end();
        });
    }
}
