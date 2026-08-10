<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Catalogue;

use LogicException;

/**
 * The system roles every workspace is provisioned with.
 *
 * A new customer must be able to invite their bookkeeper on day one without first
 * designing a permission model. These templates are that starting point — and they
 * remain editable, because a template that cannot be adjusted becomes a reason to give
 * everyone Administrator.
 *
 * `level` orders roles for display and, more importantly, prevents privilege
 * escalation: a user may not assign a role at or above their own highest level.
 */
final readonly class RoleTemplate
{
    /**
     * @param  list<string>  $permissions  Permission names, or ['*'] for the owner.
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public int $level,
        public array $permissions,
        public bool $isOwner = false,
    ) {}

    /**
     * @return list<RoleTemplate>
     */
    public static function all(): array
    {
        return [
            new self(
                name: 'owner',
                label: 'Owner',
                description: 'Full control of the workspace, including billing and the transfer of ownership. Holds every capability implicitly.',
                level: 100,
                // The owner is granted implicitly by the Gate::before rule rather than by
                // an exhaustive pivot table, so a capability added in a later phase is
                // never accidentally withheld from the person who pays for the product.
                permissions: ['*'],
                isOwner: true,
            ),

            new self(
                name: 'administrator',
                label: 'Administrator',
                description: 'Manages users, roles, companies and settings. Cannot transfer ownership.',
                level: 90,
                permissions: PermissionCatalogue::tenantGrantableNames(),
            ),

            new self(
                name: 'accountant',
                label: 'Accountant',
                description: 'Responsible for the books. Reads the audit trail and manages company configuration, but does not administer users or roles.',
                level: 60,
                permissions: [
                    'identity.users.view',
                    'identity.login_history.view',
                    'authorization.roles.view',
                    'organization.companies.view',
                    'organization.companies.update',
                    'organization.branches.view',
                    'organization.branches.create',
                    'organization.branches.update',
                    'organization.memberships.view',
                    'settings.workspace.view',
                    'settings.company.view',
                    'settings.company.update',
                    'audit.logs.view',
                    'audit.logs.export',
                    'audit.activity.view',

                    // The whole ledger. An accountant is who the drafting/posting split exists to
                    // put on the deciding side of it — including closing a period, which is a
                    // professional judgement about whether a month is final.
                    'accounting.accounts.view',
                    'accounting.accounts.manage',
                    'accounting.journals.view',
                    'accounting.journals.draft',
                    'accounting.journals.post',
                    'accounting.journals.reverse',
                    'accounting.periods.view',
                    'accounting.periods.close',
                    'accounting.opening-balances.manage',
                    'accounting.reports.view',

                    // Customers, including their credit terms. An accountant is who decides how much
                    // a customer may owe.
                    'sales.customers.view',
                    'sales.customers.manage',
                ],
            ),

            new self(
                name: 'bookkeeper',
                label: 'Bookkeeper',
                description: 'Enters day-to-day transactions. Sees the organisation structure but changes none of it.',
                level: 40,
                permissions: [
                    'identity.users.view',
                    'organization.companies.view',
                    'organization.branches.view',
                    'settings.company.view',
                    'audit.activity.view',

                    // Drafts, never posts. The whole point of the split: a bookkeeper records what
                    // happened and an accountant decides it is part of the record. Reports are
                    // included because a bookkeeper who cannot see a trial balance cannot tell
                    // whether the entry they just drafted makes sense.
                    'accounting.accounts.view',
                    'accounting.journals.view',
                    'accounting.journals.draft',
                    'accounting.periods.view',
                    'accounting.reports.view',

                    // Maintains customers, because entering day-to-day sales means creating the
                    // customer you are selling to. Managing them includes the credit limit, which is
                    // the one part of this a business may want to withhold — a tenant that cares can
                    // build a role granting only `view`, which is exactly why the two are separate.
                    'sales.customers.view',
                    'sales.customers.manage',
                ],
            ),

            new self(
                name: 'viewer',
                label: 'Viewer',
                description: 'Read-only access, for an owner’s accountant, a lender or an auditor.',
                level: 10,
                permissions: [
                    'organization.companies.view',
                    'organization.branches.view',
                    'settings.company.view',

                    // Reads the books and changes nothing. This is the role an owner's external
                    // accountant, a lender or an auditor is given, so the reports matter more here
                    // than anywhere else.
                    'accounting.accounts.view',
                    'accounting.journals.view',
                    'accounting.periods.view',
                    'accounting.reports.view',

                    // Reads who the business sells to and on what terms, which an auditor reviewing
                    // receivables needs, and changes none of it.
                    'sales.customers.view',
                ],
            ),
        ];
    }

    public static function owner(): self
    {
        foreach (self::all() as $template) {
            if ($template->isOwner) {
                return $template;
            }
        }

        // Unreachable while `all()` contains the owner template; asserted rather than
        // silently returning a role with no permissions, which would lock a new
        // customer out of their own workspace.
        throw new LogicException('The role template catalogue must define exactly one owner role.');
    }

    public function grantsEverything(): bool
    {
        return $this->permissions === ['*'];
    }

    /**
     * Resolved permission names, with the wildcard expanded and platform-only
     * capabilities excluded.
     *
     * @return list<string>
     */
    public function resolvedPermissions(): array
    {
        if ($this->grantsEverything()) {
            return PermissionCatalogue::tenantGrantableNames();
        }

        $grantable = PermissionCatalogue::tenantGrantableNames();

        return array_values(array_filter(
            $this->permissions,
            static fn (string $name): bool => in_array($name, $grantable, true),
        ));
    }
}
