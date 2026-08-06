<?php

declare(strict_types=1);

namespace Asids\Core\Audit\Policies;

use Asids\Core\Identity\Domain\Models\User;

/**
 * The activity feed is a read-only product surface; entries are written by ActivityLogger, never
 * by a request.
 */
final class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.activity.view');
    }
}
