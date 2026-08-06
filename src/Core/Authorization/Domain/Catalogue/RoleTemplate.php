<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Catalogue;

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
        throw new \LogicException('The role template catalogue must define exactly one owner role.');
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
