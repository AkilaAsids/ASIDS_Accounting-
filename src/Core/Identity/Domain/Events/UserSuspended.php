<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Access was withdrawn temporarily. The reason is carried because "why was I locked out"
 * is the first question the affected user asks, and the audit trail must be able to answer
 * it without a support ticket.
 */
final class UserSuspended
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $reason,
        public readonly User $suspendedBy,
    ) {}
}
