<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Policies;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Settings\Domain\Enums\SettingScope;

/**
 * Authorisation for settings, which differs by scope.
 *
 * Personal preferences need no permission — a user changing their own date format is not an
 * administrative act, and requiring a permission for it would mean a bookkeeper has to ask
 * someone to change how dates look. Workspace and company scopes are privileged. System scope is
 * ASIDS-only and unreachable through the API at all.
 */
final class SettingPolicy
{
    public function viewAtScope(User $user, SettingScope $scope): bool
    {
        return match ($scope) {
            SettingScope::User => true,
            SettingScope::Company => $user->can('settings.company.view'),
            SettingScope::Tenant => $user->can('settings.workspace.view'),
            SettingScope::System => $user->is_platform_admin,
        };
    }

    public function updateAtScope(User $user, SettingScope $scope): bool
    {
        return match ($scope) {
            SettingScope::User => true,
            SettingScope::Company => $user->can('settings.company.update'),
            SettingScope::Tenant => $user->can('settings.workspace.update'),
            // Platform settings are changed by deployment, not by a request — there is no
            // endpoint, and this returns false even for staff so that adding one later is a
            // deliberate decision rather than an accident.
            SettingScope::System => false,
        };
    }
}
