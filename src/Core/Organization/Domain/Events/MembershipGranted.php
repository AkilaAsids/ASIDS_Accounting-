<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user gained access to a company's books.
 *
 * An access-control change, so it belongs in the audit trail next to role assignments — the
 * two together are what an auditor reconstructs when asking who could have entered a given
 * transaction.
 */
final class MembershipGranted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CompanyMembership $membership,
        public readonly User $grantedBy,
    ) {}
}
