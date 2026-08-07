<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Application\Services;

use Asids\Core\Identity\Application\DTOs\CreateUserData;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Events\UserActivated;
use Asids\Core\Identity\Domain\Events\UserDeactivated;
use Asids\Core\Identity\Domain\Events\UserInvited;
use Asids\Core\Identity\Domain\Events\UserSuspended;
use Asids\Core\Identity\Domain\Exceptions\SeatLimitReached;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * User lifecycle within a workspace.
 *
 * Accounts are retired, never deleted. An accounting system must keep the identity that
 * approved a journal entry three years ago resolvable, so `deactivate` is the terminal
 * state and `delete` does not exist on this service.
 */
final readonly class UserService
{
    public function __construct(
        private TenantContext $tenantContext,
        private PasswordPolicyService $passwords,
        private AccountLinkService $links,
        private MembershipService $memberships,
        private TwoFactorService $twoFactor,
    ) {}

    /**
     * Create a user directly, with a password already chosen.
     *
     * Used by provisioning for the workspace owner and by the platform back office. Ordinary
     * user creation goes through `invite`, which proves control of the address.
     */
    public function create(CreateUserData $data, ?User $createdBy = null): User
    {
        $this->assertEmailAvailable($data->email);
        $this->assertSeatAvailable();

        return DB::transaction(function () use ($data, $createdBy): User {
            $user = new User;

            $user->fill([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'email' => $data->email,
                'phone' => $data->phone,
                'job_title' => $data->jobTitle,
                'employee_number' => $data->employeeNumber,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
            ]);

            $user->status = $data->activateImmediately
                ? UserStatus::Active
                : UserStatus::PendingInvitation;

            $user->invited_by_id = $createdBy?->getKey();

            if ($data->activateImmediately) {
                $user->invitation_accepted_at = now();
                // The address is treated as verified for a directly created account: either
                // sign-up proved it, or a platform operator vouched for it. An unverified
                // active account would be able to receive password resets at an address
                // nobody has confirmed.
                $user->email_verified_at = now();
            }

            $user->save();

            // Written after the insert so PasswordPolicyService can archive the outgoing hash
            // and stamp `password_changed_at` through its single code path, rather than this
            // method duplicating that logic.
            if ($data->password !== null) {
                $this->passwords->set($user, $data->password, $data->mustChangePassword);
            }

            $this->applyRoles($user, $data->roleIds);
            $this->applyCompanies($user, $data->companyIds, $createdBy ?? $user);

            return $user;
        });
    }

    /**
     * Invite a user. They set their own password by following a signed link.
     */
    public function invite(CreateUserData $data, User $invitedBy): User
    {
        $this->assertEmailAvailable($data->email);
        $this->assertSeatAvailable();

        $user = DB::transaction(function () use ($data, $invitedBy): User {
            $user = new User;

            $user->fill([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'email' => $data->email,
                'phone' => $data->phone,
                'job_title' => $data->jobTitle,
                'employee_number' => $data->employeeNumber,
                'timezone' => $data->timezone ?? $this->tenantContext->require()->timezone,
                'locale' => $data->locale ?? $this->tenantContext->require()->locale,
            ]);

            // No password at all, rather than a random one: a null credential is what makes
            // the invitation link single-use, and it means there is no valid password in
            // existence that the invitee has not chosen.
            $user->status = UserStatus::PendingInvitation;
            $user->invited_by_id = $invitedBy->getKey();
            $user->invited_at = now();
            $user->save();

            $this->applyRoles($user, $data->roleIds);
            $this->applyCompanies($user, $data->companyIds, $invitedBy);

            return $user;
        });

        // Dispatched after commit so the notification cannot reference a rolled-back user.
        UserInvited::dispatch($user, $invitedBy, $this->links->invitationUrl($user));

        return $user;
    }

    /**
     * Complete an invitation: the invitee sets their password and the account goes live.
     */
    public function acceptInvitation(User $user, string $password): User
    {
        if ($user->status !== UserStatus::PendingInvitation) {
            throw BusinessRuleViolation::make(
                code: 'invitation-already-accepted',
                message: 'This invitation has already been accepted. Sign in instead, or reset your password.',
            );
        }

        $activated = DB::transaction(function () use ($user, $password): User {
            // Setting the password changes the credential fingerprint, which is what
            // invalidates the invitation link — the single-use guarantee, with no token to
            // store or expire.
            $this->passwords->set($user, $password);

            $user->status = UserStatus::Active;
            $user->invitation_accepted_at = now();
            // Following a link sent to the address proves control of it.
            $user->email_verified_at ??= now();
            $user->save();

            return $user;
        });

        UserActivated::dispatch($activated);

        return $activated;
    }

    /**
     * Reset a password from a signed link. Also unlocks the account: a user who has
     * forgotten their password will usually have tripped the lockout on the way here.
     */
    public function resetPassword(User $user, string $password): User
    {
        if ($user->status === UserStatus::Deactivated) {
            throw BusinessRuleViolation::make(
                code: 'account-deactivated',
                message: 'This account is no longer active and its password cannot be reset.',
            );
        }

        return DB::transaction(function () use ($user, $password): User {
            $this->passwords->set($user, $password);

            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            // Every other session is invalidated: the usual reason for a reset is that the
            // old credential may be compromised, and leaving live sessions open defeats it.
            if (config('asids.auth.session.logout_other_devices_on_password_change')) {
                $this->invalidateSessions($user);
            }

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        // The address is the login identifier and the target of every security notification,
        // so a change re-opens verification rather than being applied silently.
        if (isset($attributes['email'])) {
            $incoming = strtolower(trim((string) $attributes['email']));

            if ($incoming !== $user->email) {
                $this->assertEmailAvailable($incoming, excluding: (string) $user->getKey());
                $user->email_verified_at = null;
            }
        }

        $user->fill($attributes);
        $user->save();

        return $user;
    }

    public function suspend(User $user, string $reason, User $actor): User
    {
        $this->assertNotSelf($user, $actor, 'suspend');
        $this->assertNotLastActiveOwner($user);

        $user->status = UserStatus::Suspended;
        $user->save();

        // Suspension has to take effect now, not at next sign-in.
        $this->invalidateSessions($user);

        UserSuspended::dispatch($user, $reason, $actor);

        return $user;
    }

    public function reinstate(User $user): User
    {
        if ($user->status !== UserStatus::Suspended) {
            return $user;
        }

        $this->assertSeatAvailable();

        $user->status = UserStatus::Active;
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();

        UserActivated::dispatch($user);

        return $user;
    }

    /**
     * The terminal state. Access ends; the identity remains resolvable for audit
     * attribution.
     */
    public function deactivate(User $user, string $reason, User $actor): User
    {
        $this->assertNotSelf($user, $actor, 'deactivate');
        $this->assertNotLastActiveOwner($user);

        $deactivated = DB::transaction(function () use ($user, $reason): User {
            $user->status = UserStatus::Deactivated;
            $user->deactivated_at = now();
            $user->deactivation_reason = $reason;
            $user->save();

            // Everything that could grant future access is withdrawn. The rows themselves are
            // retained (revocation is timestamped) so the historical picture survives.
            $user->tokens()->update(['revoked_at' => now(), 'revocation_reason' => 'account_deactivated']);
            $user->devices()->update(['revoked_at' => now(), 'trusted_at' => null, 'trust_expires_at' => null]);

            $this->invalidateSessions($user);

            return $user;
        });

        UserDeactivated::dispatch($deactivated, $reason, $actor);

        return $deactivated;
    }

    /**
     * Administrative password reset: sends the user a link rather than setting a password on
     * their behalf, so no administrator ever knows another user's credential.
     */
    public function sendPasswordResetLink(User $user): string
    {
        return $this->links->passwordResetUrl($user);
    }

    /**
     * Clear another user's second factor so they can re-enrol — the "I lost my phone and my
     * recovery codes" path.
     *
     * A sensitive capability: it removes a security control on someone else's account, which
     * is why the permission is flagged sensitive and the route demands step-up authentication.
     */
    public function resetTwoFactor(User $user, User $actor): User
    {
        $this->assertNotSelf($user, $actor, 'reset two factor for');

        $this->twoFactor->disable($user);

        return $user;
    }

    public function setDefaultCompany(User $user, Company $company): User
    {
        $this->memberships->setDefault($user, $company);

        return $user->refresh();
    }

    /**
     * Records that the user did something, for the "last seen" column and the idle-session
     * sweep.
     *
     * Written with a bare update rather than a model save: it runs on every authenticated
     * request, and a full model save would fire observers and audit writes for a timestamp
     * nobody audits.
     */
    public function touchActivity(User $user): void
    {
        User::query()->whereKey($user->getKey())->update(['last_activity_at' => now()]);
    }

    public static function sessionEpochKey(User $user): string
    {
        return 'session-epoch:'.$user->getKey();
    }

    // ── Invariants ──────────────────────────────────────────────────────────

    private function assertEmailAvailable(string $email, ?string $excluding = null): void
    {
        $exists = User::query()
            ->whereRaw('lower(email) = ?', [strtolower($email)])
            ->when($excluding !== null, static fn ($query) => $query->whereKeyNot($excluding))
            ->exists();

        if ($exists) {
            throw ResourceConflict::duplicate('user', 'e-mail address', $email);
        }
    }

    /**
     * Seats are counted as active plus pending invitations, because an invitation that has
     * been sent has effectively consumed the seat — otherwise a workspace could invite past
     * its limit and only discover it when people started accepting.
     */
    private function assertSeatAvailable(): void
    {
        $tenant = $this->tenantContext->require();
        $limit = $tenant->userLimit();

        $consumed = User::query()
            ->whereIn('status', [UserStatus::Active->value, UserStatus::PendingInvitation->value])
            ->count();

        if ($consumed >= $limit) {
            throw SeatLimitReached::at($limit);
        }
    }

    private function assertNotSelf(User $target, User $actor, string $verb): void
    {
        if ($target->getKey() === $actor->getKey()) {
            throw BusinessRuleViolation::make(
                code: 'cannot-act-on-self',
                message: sprintf('You cannot %s your own account.', $verb),
            );
        }
    }

    /**
     * A workspace must always retain one owner who can actually sign in. Suspending or
     * deactivating the last one would leave nobody able to manage billing or invite users —
     * a state only ASIDS support could repair.
     */
    private function assertNotLastActiveOwner(User $user): void
    {
        if (! $user->isTenantOwner()) {
            return;
        }

        $otherActiveOwners = User::query()
            ->active()
            ->whereKeyNot($user->getKey())
            ->whereHas('roles', static fn ($query) => $query->where('is_owner', true))
            ->exists();

        if (! $otherActiveOwners) {
            throw BusinessRuleViolation::make(
                code: 'last-active-owner',
                message: 'This is the only active owner of the workspace. Make another user an owner first.',
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $roleIds
     */
    private function applyRoles(User $user, array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        // Assigned through the pivot directly rather than through RoleService, because that
        // service enforces "strictly below the actor's level" — a rule that has no meaning
        // during provisioning, where the actor is the owner being created.
        $rows = array_map(
            static fn (string $roleId): array => [
                'role_id' => $roleId,
                'model_type' => 'user',
                'model_uuid' => $user->getKey(),
                'tenant_id' => $user->tenant_id,
            ],
            array_values(array_unique($roleIds)),
        );

        DB::table('model_has_roles')->insertOrIgnore($rows);

        $user->forgetAuthorizationState();
    }

    /**
     * @param  list<string>  $companyIds
     */
    private function applyCompanies(User $user, array $companyIds, User $grantedBy): void
    {
        foreach (array_unique($companyIds) as $companyId) {
            $company = Company::query()->find($companyId);

            if ($company === null) {
                continue;
            }

            $this->memberships->grant(
                company: $company,
                user: $user,
                grantedBy: $grantedBy,
            );
        }
    }

    /**
     * Ends every session belonging to the user.
     *
     * Deletes the database session rows and bumps a per-user epoch in the cache. The epoch is
     * what makes this work with the Redis session driver, where sessions are not enumerable
     * by user: `EnsureSessionIsCurrent` compares the session's epoch against the stored one
     * on each request.
     */
    private function invalidateSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        cache()->forever(self::sessionEpochKey($user), (string) Str::uuid7());
    }
}
