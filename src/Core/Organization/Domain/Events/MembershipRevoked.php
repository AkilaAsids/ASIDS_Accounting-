<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MembershipRevoked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly CompanyMembership $membership,
        public readonly User $revokedBy,
    ) {}
}
