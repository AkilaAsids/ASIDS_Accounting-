<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Events;

use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A role's permission set changed.
 *
 * Carries the before and after sets rather than just the role, because the audit
 * question an auditor actually asks is "what changed, and who changed it" — and
 * reconstructing that from the current state alone is impossible.
 */
final class RolePermissionsChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $previousPermissions
     * @param  list<string>  $currentPermissions
     */
    public function __construct(
        public readonly Role $role,
        public readonly array $previousPermissions,
        public readonly array $currentPermissions,
        public readonly User $actor,
    ) {}

    /**
     * @return list<string>
     */
    public function granted(): array
    {
        return array_values(array_diff($this->currentPermissions, $this->previousPermissions));
    }

    /**
     * @return list<string>
     */
    public function revoked(): array
    {
        return array_values(array_diff($this->previousPermissions, $this->currentPermissions));
    }
}
