<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A security control was removed. `disabledBy` differs from `user` when an administrator
 * cleared it on someone's behalf, which is the case worth alerting on.
 */
final class TwoFactorDisabled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly User $disabledBy,
    ) {}
}
