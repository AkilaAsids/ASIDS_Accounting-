<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new workspace is live.
 *
 * Deliberately fired *after* the provisioning transaction commits, so a listener
 * that sends a welcome e-mail or notifies the sales channel can never reference a
 * tenant that was rolled back.
 */
final class TenantProvisioned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $owner,
    ) {}
}
