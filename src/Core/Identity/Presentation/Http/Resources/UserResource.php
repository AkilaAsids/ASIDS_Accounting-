<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Resources;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * Security telemetry (lockout state, failed attempts, last IP) is exposed only to the
     * account holder or to someone holding the sign-in-history permission. A colleague with
     * "view users" has no business knowing when someone last signed in and from where.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isSelf = $viewer instanceof User && $viewer->getKey() === $this->id;
        $seesSecurity = $isSelf || ($viewer?->can('identity.login_history.view') ?? false);

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'initials' => $this->initials(),
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'employee_number' => $this->employee_number,
            'avatar_path' => $this->avatar_path,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_platform_admin' => $this->is_platform_admin,
            'is_owner' => $this->isTenantOwner(),

            'preferences' => [
                'locale' => $this->effectiveLocale(),
                'timezone' => $this->effectiveTimezone(),
                'theme' => $this->theme,
            ],

            'two_factor_enabled' => $this->hasTwoFactorEnabled(),

            'default_company' => $this->whenLoaded('defaultCompany', fn (): ?array => $this->defaultCompany === null ? null : [
                'id' => $this->defaultCompany->getKey(),
                'name' => $this->defaultCompany->name,
                'code' => $this->defaultCompany->code,
            ]),

            'roles' => $this->whenLoaded('roles', fn (): array => $this->roles->map(static fn ($role): array => [
                'id' => $role->getKey(),
                'name' => $role->name,
                'label' => $role->label,
                'level' => $role->level,
                'is_owner' => $role->is_owner,
            ])->values()->all()),

            'company_count' => $this->whenCounted('memberships'),

            'security' => $this->when($seesSecurity, fn (): array => [
                'last_login_at' => $this->last_login_at?->toIso8601String(),
                'last_login_ip' => $this->last_login_ip,
                'last_activity_at' => $this->last_activity_at?->toIso8601String(),
                'password_changed_at' => $this->password_changed_at?->toIso8601String(),
                'password_expired' => $this->passwordHasExpired(),
                'must_change_password' => $this->must_change_password,
                'is_locked' => $this->isLocked(),
                'locked_until' => $this->locked_until?->toIso8601String(),
                'failed_login_attempts' => $this->failed_login_attempts,
            ]),

            'invited_at' => $this->invited_at?->toIso8601String(),
            'invitation_accepted_at' => $this->invitation_accepted_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
