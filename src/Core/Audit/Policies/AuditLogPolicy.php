<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Policies;

use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Identity\Domain\Models\User;

/**
 * Authorisation for the audit trail.
 *
 * There are no write methods, and their absence is the enforcement: the trail is append-only at
 * the database level, so a policy method that could ever return true for an update would be
 * misleading about what the system permits.
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.logs.view');
    }

    public function view(User $user, AuditLog $entry): bool
    {
        // Tenant scoping is already applied by the global scope and by row level security; this
        // is the permission half of the check.
        return $user->can('audit.logs.view');
    }

    /**
     * Exporting hands a copy of the workspace's entire change history to whoever receives the
     * file, so it is a separate, sensitive capability rather than a variant of viewing.
     */
    public function export(User $user): bool
    {
        return $user->can('audit.logs.export');
    }

    /**
     * Running the chain verification. Read-only, but it reveals whether the trail has been
     * tampered with, which is information an attacker would like to have.
     */
    public function verify(User $user): bool
    {
        return $user->can('audit.logs.verify');
    }
}
