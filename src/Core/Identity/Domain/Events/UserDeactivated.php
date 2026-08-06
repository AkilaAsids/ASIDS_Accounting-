<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The terminal state: the person has left. Their identity remains resolvable so the
 * transactions they approved keep their attribution.
 */
final class UserDeactivated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $reason,
        public readonly User $deactivatedBy,
    ) {}
}
