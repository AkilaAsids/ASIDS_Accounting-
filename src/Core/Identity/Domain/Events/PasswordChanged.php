<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A credential changed. Always notified to the account holder, whoever initiated it: an
 * unexpected "your password was changed" mail is how an account takeover gets caught.
 */
final class PasswordChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        /** self-service | reset-link | administrative */
        public readonly string $initiatedBy,
    ) {}
}
