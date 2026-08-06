<?php

declare(strict_types=1);

namespace Asids\Core\Authorization\Domain\Catalogue;

/**
 * The complete list of capabilities the platform offers.
 *
 * This file is the security surface of the product. A reviewer can read it top to
 * bottom and enumerate everything any user could ever be permitted to do — which is
 * impossible when permissions are strings scattered through controllers.
 *
 * Rules for adding to it:
 *
 *   * Name capabilities after the *business* action, not the HTTP verb.
 *     `invoice.approve`, never `invoice.patch`.
 *   * Mark `sensitive: true` if the capability moves money, alters posted history, or
 *     weakens another user's security.
 *   * Mark `platformOnly: true` for ASIDS staff capabilities, and prefix the module
 *     with `platform` so the gate short circuit in AuthServiceProvider matches.
 *   * Only add a capability when the code that checks it exists. An unchecked
 *     permission gives a false sense of control.
 *
 * Phase 1 covers identity, authorization, organization, settings and audit. Accounting,
 * sales, purchasing, inventory and payroll capabilities arrive with their phases.
 */
final class PermissionCatalogue
{
    /**
     * @return list<PermissionDefinition>
     */
    public static function all(): array
    {
        return [
            ...self::identity(),
            ...self::authorization(),
            ...self::organization(),
            ...self::settings(),
            ...self::audit(),
            ...self::platform(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            static fn (PermissionDefinition $definition): string => $definition->name(),
            self::all(),
        );
    }

    /**
     * Every capability a tenant role may hold — that is, everything except platform
     * staff capabilities. Used by RoleProvisioner when building the owner role and by
     * the validation of a customer-defined role.
     *
     * @return list<string>
     */
    public static function tenantGrantableNames(): array
    {
        return array_values(array_map(
            static fn (PermissionDefinition $definition): string => $definition->name(),
            array_filter(
                self::all(),
                static fn (PermissionDefinition $definition): bool => ! $definition->platformOnly,
            ),
        ));
    }

    public static function find(string $name): ?PermissionDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->name() === $name) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function identity(): array
    {
        return [
            new PermissionDefinition('identity', 'users', 'view', 'View users', 'See the list of users and their profiles.', sortOrder: 10),
            new PermissionDefinition('identity', 'users', 'invite', 'Invite users', 'Send an invitation to a new user.', sortOrder: 20),
            new PermissionDefinition('identity', 'users', 'update', 'Edit users', 'Change another user’s name, contact details and preferences.', sortOrder: 30),
            new PermissionDefinition('identity', 'users', 'suspend', 'Suspend users', 'Temporarily prevent a user from signing in.', sensitive: true, sortOrder: 40),
            new PermissionDefinition('identity', 'users', 'deactivate', 'Deactivate users', 'Retire a user account permanently, retaining it for audit attribution.', sensitive: true, sortOrder: 50),

            // Resetting a password and clearing a second factor are account-takeover
            // primitives in the wrong hands, so both are sensitive and separate from
            // ordinary editing.
            new PermissionDefinition('identity', 'credentials', 'reset_password', 'Reset passwords', 'Force a password reset for another user.', sensitive: true, sortOrder: 60),
            new PermissionDefinition('identity', 'credentials', 'reset_two_factor', 'Reset two factor', 'Remove another user’s two factor authentication so they can re-enrol.', sensitive: true, sortOrder: 70),

            new PermissionDefinition('identity', 'devices', 'view', 'View sign-in devices', 'See the devices a user has signed in from.', sortOrder: 80),
            new PermissionDefinition('identity', 'devices', 'revoke', 'Revoke devices', 'Remove a device’s recognition and trust.', sortOrder: 90),

            new PermissionDefinition('identity', 'login_history', 'view', 'View sign-in history', 'See successful and failed sign-in attempts.', sortOrder: 100),

            new PermissionDefinition('identity', 'tokens', 'view', 'View API tokens', 'See the API tokens issued in this workspace.', sortOrder: 110),
            new PermissionDefinition('identity', 'tokens', 'create', 'Create API tokens', 'Issue a personal access token for an integration.', sensitive: true, sortOrder: 120),
            new PermissionDefinition('identity', 'tokens', 'revoke', 'Revoke API tokens', 'Disable an issued API token.', sortOrder: 130),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function authorization(): array
    {
        return [
            new PermissionDefinition('authorization', 'roles', 'view', 'View roles', 'See the roles defined in this workspace and their permissions.', sortOrder: 10),
            new PermissionDefinition('authorization', 'roles', 'create', 'Create roles', 'Define a new role.', sortOrder: 20),
            new PermissionDefinition('authorization', 'roles', 'update', 'Edit roles', 'Change a role’s name, description and permissions.', sensitive: true, sortOrder: 30),
            new PermissionDefinition('authorization', 'roles', 'delete', 'Delete roles', 'Remove a role that is no longer needed.', sensitive: true, sortOrder: 40),

            // Assignment is the capability that actually escalates privilege: anyone who
            // can assign roles can grant themselves anything the role holds.
            new PermissionDefinition('authorization', 'roles', 'assign', 'Assign roles', 'Grant or remove a user’s roles.', sensitive: true, sortOrder: 50),

            new PermissionDefinition('authorization', 'permissions', 'view', 'View permissions', 'See the catalogue of available permissions.', sortOrder: 60),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function organization(): array
    {
        return [
            new PermissionDefinition('organization', 'companies', 'view', 'View companies', 'See the companies in this workspace.', sortOrder: 10),
            new PermissionDefinition('organization', 'companies', 'create', 'Create companies', 'Add a company to this workspace.', sortOrder: 20),
            new PermissionDefinition('organization', 'companies', 'update', 'Edit companies', 'Change a company’s details and statutory registrations.', sensitive: true, sortOrder: 30),
            new PermissionDefinition('organization', 'companies', 'archive', 'Archive companies', 'Close a company to further transactions.', sensitive: true, sortOrder: 40),

            new PermissionDefinition('organization', 'branches', 'view', 'View branches', 'See a company’s branches.', sortOrder: 50),
            new PermissionDefinition('organization', 'branches', 'create', 'Create branches', 'Add a branch to a company.', sortOrder: 60),
            new PermissionDefinition('organization', 'branches', 'update', 'Edit branches', 'Change a branch’s details.', sortOrder: 70),
            new PermissionDefinition('organization', 'branches', 'archive', 'Archive branches', 'Close a branch.', sortOrder: 80),

            new PermissionDefinition('organization', 'memberships', 'view', 'View company access', 'See which users may access which companies.', sortOrder: 90),
            new PermissionDefinition('organization', 'memberships', 'grant', 'Grant company access', 'Give a user access to a company’s books.', sensitive: true, sortOrder: 100),
            new PermissionDefinition('organization', 'memberships', 'revoke', 'Revoke company access', 'Remove a user’s access to a company.', sortOrder: 110),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function settings(): array
    {
        return [
            new PermissionDefinition('settings', 'workspace', 'view', 'View workspace settings', 'See settings that apply to the whole workspace.', sortOrder: 10),
            new PermissionDefinition('settings', 'workspace', 'update', 'Edit workspace settings', 'Change settings that apply to the whole workspace.', sensitive: true, sortOrder: 20),
            new PermissionDefinition('settings', 'company', 'view', 'View company settings', 'See a company’s settings.', sortOrder: 30),
            new PermissionDefinition('settings', 'company', 'update', 'Edit company settings', 'Change a company’s settings.', sensitive: true, sortOrder: 40),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function audit(): array
    {
        return [
            new PermissionDefinition('audit', 'logs', 'view', 'View audit trail', 'Read the immutable record of every change.', sortOrder: 10),
            new PermissionDefinition('audit', 'logs', 'export', 'Export audit trail', 'Download the audit trail for an external auditor.', sensitive: true, sortOrder: 20),
            new PermissionDefinition('audit', 'logs', 'verify', 'Verify audit integrity', 'Run the hash-chain verification and see its result.', sortOrder: 30),
            new PermissionDefinition('audit', 'activity', 'view', 'View activity feed', 'See the human-readable activity feed.', sortOrder: 40),
        ];
    }

    /**
     * ASIDS staff capabilities. Never granted to a tenant role.
     *
     * @return list<PermissionDefinition>
     */
    private static function platform(): array
    {
        return [
            new PermissionDefinition('platform', 'tenants', 'view', 'View workspaces', 'See every workspace on the platform.', platformOnly: true, sortOrder: 10),
            new PermissionDefinition('platform', 'tenants', 'create', 'Create workspaces', 'Provision a workspace on a customer’s behalf.', sensitive: true, platformOnly: true, sortOrder: 20),
            new PermissionDefinition('platform', 'tenants', 'suspend', 'Suspend workspaces', 'Withhold access to a workspace, retaining its data.', sensitive: true, platformOnly: true, sortOrder: 30),
            new PermissionDefinition('platform', 'tenants', 'activate', 'Activate workspaces', 'Restore a suspended workspace.', sensitive: true, platformOnly: true, sortOrder: 40),
            new PermissionDefinition('platform', 'audit', 'view', 'View platform audit trail', 'Read audit entries across every workspace.', sensitive: true, platformOnly: true, sortOrder: 50),
        ];
    }
}
