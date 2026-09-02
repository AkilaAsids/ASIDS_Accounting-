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
            ...self::accounting(),
            ...self::sales(),
            ...self::purchasing(),
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
     * The accounting core.
     *
     * The split that shapes this list is drafting from posting. A bookkeeper records what happened; an
     * accountant decides it is part of the record. That is how a Sri Lankan SME with one qualified
     * accountant and two data-entry staff actually operates, and collapsing the two into one
     * "manage journals" capability would mean the split could only be enforced by asking people
     * nicely.
     *
     * Everything that alters posted history or closes a period is marked sensitive.
     *
     * @return list<PermissionDefinition>
     */
    private static function accounting(): array
    {
        return [
            new PermissionDefinition('accounting', 'accounts', 'view', 'View chart of accounts', 'See the accounts a company keeps its books in.', sortOrder: 10),
            new PermissionDefinition('accounting', 'accounts', 'manage', 'Manage chart of accounts', 'Add, rename, reclassify and archive accounts.', sensitive: true, sortOrder: 20),

            new PermissionDefinition('accounting', 'journals', 'view', 'View journal entries', 'Read journal entries and their lines.', sortOrder: 30),
            new PermissionDefinition('accounting', 'journals', 'draft', 'Draft journal entries', 'Prepare entries for someone else to post.', sortOrder: 40),
            // Posting is what makes an entry part of the financial record, and it cannot be undone
            // except by a reversal that is itself visible for ever.
            new PermissionDefinition('accounting', 'journals', 'post', 'Post journal entries', 'Commit an entry to the ledger.', sensitive: true, sortOrder: 50),
            new PermissionDefinition('accounting', 'journals', 'reverse', 'Reverse journal entries', 'Undo a posted entry by writing its mirror.', sensitive: true, sortOrder: 60),

            new PermissionDefinition('accounting', 'periods', 'view', 'View fiscal calendar', 'See fiscal years and periods and their state.', sortOrder: 70),
            new PermissionDefinition('accounting', 'periods', 'close', 'Close periods', 'Stop further postings into a period.', sensitive: true, sortOrder: 80),
            // Reopening changes figures that may already have been filed with a bank or a tax
            // authority, which is why it is separate from closing rather than implied by it.
            new PermissionDefinition('accounting', 'periods', 'reopen', 'Reopen periods', 'Allow postings into a closed period again.', sensitive: true, sortOrder: 90),
            new PermissionDefinition('accounting', 'periods', 'close-year', 'Close the financial year', 'Move the year\'s result to retained earnings.', sensitive: true, sortOrder: 100),

            new PermissionDefinition('accounting', 'opening-balances', 'manage', 'Record opening balances', 'Enter the balances a business arrives with.', sensitive: true, sortOrder: 110),

            new PermissionDefinition('accounting', 'reports', 'view', 'View accounting reports', 'Read the trial balance and account ledgers.', sortOrder: 120),
        ];
    }

    /**
     * Selling: customers now, invoices as they arrive.
     *
     * A group of its own rather than more `accounting` capabilities, because the people differ. A sales
     * administrator maintains customers and raises invoices without any business in the chart of
     * accounts or the fiscal calendar, and a role template that had to grant ledger access to allow
     * invoicing would hand out far more than it meant to.
     *
     * @return list<PermissionDefinition>
     */
    private static function sales(): array
    {
        return [
            new PermissionDefinition('sales', 'customers', 'view', 'View customers', 'See the customers a company sells to and their terms.', sortOrder: 10),
            // Sensitive because it includes the credit limit and the payment terms. Both decide how
            // much a customer can owe before anyone is asked, which is why the audit trail records
            // every change to them.
            new PermissionDefinition('sales', 'customers', 'manage', 'Manage customers', 'Add, edit, archive and restore customers, including their credit terms.', sensitive: true, sortOrder: 20),

            new PermissionDefinition('sales', 'tax-codes', 'view', 'View tax codes', 'See the tax codes a company charges and the rates behind them.', sortOrder: 30),
            // Sensitive, and of everything in this group this is the one that most deserves the marker. A
            // wrong rate is not a wrong screen: it changes what every invoice under that code charges, what
            // the ledger posts to the tax liability, and what the return reports — and it does all three
            // while the books still balance, so nothing downstream detects it.
            new PermissionDefinition('sales', 'tax-codes', 'manage', 'Manage tax codes', 'Add, edit, deactivate and delete tax codes and their effective-dated rates.', sensitive: true, sortOrder: 40),

            new PermissionDefinition('sales', 'invoices', 'view', 'View sales invoices', 'Read invoices and their lines.', sortOrder: 50),
            // Not sensitive, and the contrast with tax codes is the point. A draft has no number, is not in the
            // ledger, and the customer has never seen it — it can be corrected or deleted with no trace and no
            // consequence. What deserves the marker is *issuing*, which commits the document to the ledger and
            // to the customer, and that capability arrives with Milestone 5 rather than being declared unused
            // here.
            new PermissionDefinition('sales', 'invoices', 'draft', 'Draft sales invoices', 'Prepare, change and delete draft invoices before they are issued.', sortOrder: 60),
            // The capability the comment above anticipated, arriving with Milestone 5. Issuing takes a number
            // from a gapless series, posts to the ledger and produces a document the customer receives — none
            // of which can be taken back except by a cancellation that is itself permanent.
            new PermissionDefinition('sales', 'invoices', 'issue', 'Issue sales invoices', 'Commit a draft to the ledger and to the customer.', sensitive: true, sortOrder: 70),
            // Separate from issuing rather than folded into it, following `accounting.journals.post` and
            // `.reverse`. A business may well let someone raise invoices without letting them undo one, and a
            // single combined ability could not express that.
            new PermissionDefinition('sales', 'invoices', 'cancel', 'Cancel sales invoices', 'Reverse an issued invoice’s posting, keeping both entries in the ledger.', sensitive: true, sortOrder: 80),

            // Reading a receivables report changes nothing, so not sensitive — the same judgement
            // `accounting.reports.view` makes about the trial balance. What it does expose is the debtor
            // book: who owes what, for how long, and whether the subledger agrees with the ledger. That is
            // why it is a capability of its own rather than folded into `customers.view`, which answers only
            // *who* the customers are.
            new PermissionDefinition('sales', 'reports', 'view', 'View receivables reports', 'Read outstanding balances, aged receivables and the AR control reconciliation.', sortOrder: 90),
        ];
    }

    /**
     * Buying: suppliers now, bills and payments as they arrive.
     *
     * A group of its own rather than folded under `sales`, because suppliers are the payable-side mirror
     * of customers, not a kind of customer. Deciding who a company pays and on what terms is a distinct
     * capability from deciding who it sells to.
     *
     * @return list<PermissionDefinition>
     */
    private static function purchasing(): array
    {
        return [
            new PermissionDefinition('purchasing', 'suppliers', 'view', 'View suppliers', 'See the suppliers a company buys from and their terms.', sortOrder: 10),
            // Sensitive because deciding who you pay is a sensitive action: `payment_terms_days` and the
            // compliance-bearing tax identification number ride on `manage`, and the audit trail records
            // every change to them.
            new PermissionDefinition('purchasing', 'suppliers', 'manage', 'Manage suppliers', 'Add, edit, archive and restore suppliers.', sensitive: true, sortOrder: 20),

            new PermissionDefinition('purchasing', 'bills', 'view', 'View bills', 'Read bills and their lines.', sortOrder: 30),
            // Not sensitive, and the contrast with posting is the point. A draft bill has no internal number,
            // is not in the ledger, and can be corrected or deleted with no trace — the mirror of
            // `sales.invoices.draft`.
            new PermissionDefinition('purchasing', 'bills', 'draft', 'Draft bills', 'Prepare, change and delete draft bills before they are posted.', sortOrder: 40),
            // Sensitive: posting takes an internal number, posts to the ledger and creates a payable — none of
            // which can be taken back except by a cancellation. The mirror of `sales.invoices.issue`.
            new PermissionDefinition('purchasing', 'bills', 'post', 'Post bills', 'Commit a draft bill to the ledger, creating the payable.', sensitive: true, sortOrder: 50),
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
