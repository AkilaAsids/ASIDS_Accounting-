<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Application\Services;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Events\MembershipGranted;
use Asids\Core\Organization\Domain\Events\MembershipRevoked;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Company-level data access.
 *
 * Distinct from roles: a role says what a person may do, a membership says whose books they
 * may touch. Both must pass. See ADR 0002.
 *
 * Revocation is a timestamp rather than a delete, so "who could see these books in March"
 * remains answerable — and so that re-granting access does not lose the original join date.
 */
final readonly class MembershipService
{
    public function grant(
        Company $company,
        User $user,
        User $grantedBy,
        ?Branch $branch = null,
        bool $makeDefault = false,
    ): CompanyMembership {
        if (! $company->isActive()) {
            throw BusinessRuleViolation::make(
                code: 'archived-company-access',
                message: 'Access cannot be granted to an archived company.',
            );
        }

        // Platform staff must not accumulate memberships: staff access to customer books
        // goes through the audited impersonation flow, and a membership would bypass it
        // silently and permanently.
        if ($user->is_platform_admin) {
            throw BusinessRuleViolation::make(
                code: 'platform-staff-membership',
                message: 'Platform staff cannot be given direct access to a customer company.',
            );
        }

        if ($branch !== null && $branch->company_id !== $company->getKey()) {
            throw BusinessRuleViolation::make(
                code: 'branch-company-mismatch',
                message: 'The selected branch does not belong to that company.',
            );
        }

        return DB::transaction(function () use ($company, $user, $grantedBy, $branch, $makeDefault): CompanyMembership {
            // A revoked membership is reinstated rather than duplicated: the unique index on
            // (company_id, user_id) covers revoked rows too, so inserting a second would
            // fail, and reinstating preserves the original `joined_at`.
            $membership = CompanyMembership::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $user->getKey())
                ->first();

            if ($membership === null) {
                $membership = new CompanyMembership();
                $membership->fill([
                    'company_id' => $company->getKey(),
                    'user_id' => $user->getKey(),
                    'branch_id' => $branch?->getKey(),
                    'granted_by_id' => $grantedBy->getKey(),
                ]);
            } else {
                $membership->revoked_at = null;
                $membership->branch_id = $branch?->getKey();
                $membership->granted_by_id = $grantedBy->getKey();
            }

            // The first company a user is given access to becomes their landing company;
            // otherwise they would sign in to an empty company switcher.
            $isFirst = ! CompanyMembership::query()
                ->active()
                ->where('user_id', $user->getKey())
                ->where('company_id', '!=', $company->getKey())
                ->exists();

            $membership->is_default = $makeDefault || $isFirst;

            if ($membership->is_default) {
                $this->clearOtherDefaults($user, $company->getKey());
            }

            $membership->save();

            if ($membership->is_default) {
                $user->default_company_id = $company->getKey();
                $user->save();
            }

            MembershipGranted::dispatch($membership, $grantedBy);

            return $membership;
        });
    }

    public function revoke(CompanyMembership $membership, User $revokedBy): void
    {
        if (! $membership->isActive()) {
            return;
        }

        DB::transaction(function () use ($membership, $revokedBy): void {
            $wasDefault = $membership->is_default;
            $user = $membership->user;

            // The table's check constraint forbids a revoked row from being the default, so
            // the flag is cleared in the same write.
            $membership->revoked_at = now();
            $membership->is_default = false;
            $membership->save();

            if ($wasDefault) {
                $this->promoteReplacementDefault($user);
            }

            MembershipRevoked::dispatch($membership, $revokedBy);
        });
    }

    /**
     * Revoke every membership of a company. Used when a company is archived.
     */
    public function revokeAllForCompany(Company $company): void
    {
        /** @var list<CompanyMembership> $memberships */
        $memberships = CompanyMembership::query()
            ->active()
            ->where('company_id', $company->getKey())
            ->with('user')
            ->get()
            ->all();

        foreach ($memberships as $membership) {
            $wasDefault = $membership->is_default;
            $user = $membership->user;

            $membership->revoked_at = now();
            $membership->is_default = false;
            $membership->save();

            if ($wasDefault) {
                $this->promoteReplacementDefault($user);
            }
        }
    }

    /**
     * Change which company a user lands in.
     */
    public function setDefault(User $user, Company $company): CompanyMembership
    {
        $membership = CompanyMembership::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where('company_id', $company->getKey())
            ->first();

        if ($membership === null) {
            throw BusinessRuleViolation::make(
                code: 'not-a-member',
                message: 'That user does not have access to the selected company.',
            );
        }

        return DB::transaction(function () use ($membership, $user, $company): CompanyMembership {
            $this->clearOtherDefaults($user, $company->getKey());

            $membership->is_default = true;
            $membership->save();

            $user->default_company_id = $company->getKey();
            $user->save();

            return $membership;
        });
    }

    /**
     * After losing their default, a user is moved to their oldest remaining company rather
     * than being left with none — otherwise their next sign-in lands nowhere and looks like
     * a total loss of access.
     */
    private function promoteReplacementDefault(User $user): void
    {
        $replacement = CompanyMembership::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->orderBy('joined_at')
            ->first();

        if ($replacement === null) {
            $user->default_company_id = null;
            $user->save();

            return;
        }

        $replacement->is_default = true;
        $replacement->save();

        $user->default_company_id = $replacement->company_id;
        $user->save();
    }

    private function clearOtherDefaults(User $user, string $exceptCompanyId): void
    {
        CompanyMembership::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', '!=', $exceptCompanyId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
