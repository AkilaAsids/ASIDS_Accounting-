<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An account can now sign in — either an invitation was accepted or a suspension lifted.
 */
final class UserActivated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly User $user) {}
}
