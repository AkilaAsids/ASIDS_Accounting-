<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Events;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user's roles changed.
 *
 * One of the highest-value events in the audit trail: privilege change is the first
 * thing an incident responder reconstructs, and the second thing an auditor samples.
 */
final class RoleAssignmentChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $previousRoles
     * @param  list<string>  $currentRoles
     */
    public function __construct(
        public readonly User $user,
        public readonly array $previousRoles,
        public readonly array $currentRoles,
        public readonly User $actor,
    ) {}

    public function isSelfAssignment(): bool
    {
        return $this->user->getKey() === $this->actor->getKey();
    }

    /**
     * @return list<string>
     */
    public function gained(): array
    {
        return array_values(array_diff($this->currentRoles, $this->previousRoles));
    }

    /**
     * @return list<string>
     */
    public function lost(): array
    {
        return array_values(array_diff($this->previousRoles, $this->currentRoles));
    }
}
