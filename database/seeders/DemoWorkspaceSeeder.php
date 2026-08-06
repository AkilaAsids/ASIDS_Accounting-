<?php

declare(strict_types=1);

namespace Database\Seeders;

use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Application\DTOs\CreateUserData;
use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Tenancy\Application\DTOs\ProvisionTenantData;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Application\Services\TenantProvisioningService;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A working demonstration workspace.
 *
 * Built by calling the real provisioning service rather than by inserting rows. That choice is
 * deliberate and doubles as the first end-to-end exercise of the platform: if
 * TenantProvisioningService, RoleProvisioner, UserService, CompanyService and MembershipService
 * do not agree with each other, `migrate --seed` fails loudly instead of a test discovering it
 * later.
 *
 * The password below is a fixed, publicly documented development credential. The guard at the
 * top of `run()` is what stops it ever reaching a real deployment.
 */
final class DemoWorkspaceSeeder extends Seeder
{
    private const string DEMO_PASSWORD = 'Asids#Demo2026!';

    private const string DEMO_SLUG = 'demo';

    public function run(
        TenantProvisioningService $provisioning,
        TenantContext $tenantContext,
        UserService $users,
        CompanyService $companies,
    ): void {
        // Not a warning — a hard stop. A known password in a production database is a breach,
        // and `--force` on a seeder is easy to type by accident during an incident.
        if (! app()->environment('local', 'staging', 'testing')) {
            throw new RuntimeException(
                'DemoWorkspaceSeeder creates an account with a publicly known password and must never run outside local, staging or testing.'
            );
        }

        if (Tenant::query()->where('slug', self::DEMO_SLUG)->exists()) {
            $this->command?->getOutput()->writeln('  <fg=yellow>Demo workspace already exists — skipping.</>');

            return;
        }

        $result = $provisioning->provision(new ProvisionTenantData(
            tenantName: 'ASIDS Demo Holdings',
            slug: self::DEMO_SLUG,
            ownerFirstName: 'Nimal',
            ownerEmail: 'owner@demo.test',
            ownerLastName: 'Perera',
            ownerPassword: self::DEMO_PASSWORD,
            legalName: 'ASIDS Demo Holdings (Pvt) Ltd',
            companyName: 'Demo Trading (Pvt) Ltd',
            trialDays: 30,
            taxIdentificationNumber: '114725896',
        ));

        $tenant = $result['tenant'];
        $owner = $result['owner'];

        // Everything below runs inside the demo workspace, so the ordinary scopes, policies and
        // row level security all apply — exactly as they would for a real customer.
        $tenantContext->runFor($tenant, function () use ($users, $companies, $owner): void {
            $this->seedStaff($users, $owner);
            $this->seedSecondCompany($companies, $owner);
        });

        $this->report($tenant->slug);
    }

    private function seedStaff(UserService $users, User $owner): void
    {
        /** @var array<string, string> $roleIds name => id */
        $roleIds = Role::query()->assignable()->pluck('id', 'name')->all();

        $staff = [
            ['Kumari', 'Silva', 'accountant@demo.test', 'accountant', 'Chief Accountant'],
            ['Ravi', 'Fernando', 'bookkeeper@demo.test', 'bookkeeper', 'Bookkeeper'],
            ['Anusha', 'Jayawardena', 'admin@demo.test', 'administrator', 'Operations Manager'],
            ['Tharindu', 'Bandara', 'viewer@demo.test', 'viewer', 'External Auditor'],
        ];

        foreach ($staff as [$firstName, $lastName, $email, $roleName, $jobTitle]) {
            $roleId = $roleIds[$roleName] ?? null;

            if ($roleId === null) {
                continue;
            }

            $users->create(new CreateUserData(
                firstName: $firstName,
                lastName: $lastName,
                email: $email,
                password: self::DEMO_PASSWORD,
                roleIds: [$roleId],
                // Every demo user is a member of the company the owner already has, so the
                // company switcher and the membership boundary are both exercised.
                companyIds: $owner->accessibleCompanyIds()->all(),
                jobTitle: $jobTitle,
                // Activated directly: an invitation flow needs a mail server, and a seeder that
                // leaves four accounts unable to sign in is not a useful demo.
                activateImmediately: true,
            ), $owner);
        }
    }

    /**
     * A second company, so the demo exercises the multi-company case — a group of two SMEs
     * under common ownership, which is the shape of the target market.
     */
    private function seedSecondCompany(CompanyService $companies, User $owner): void
    {
        $companies->create(new CreateCompanyData(
            name: 'Demo Logistics (Pvt) Ltd',
            legalName: 'Demo Logistics (Private) Limited',
            code: 'DLOG',
            baseCurrencyCode: 'LKR',
            fiscalYearStartMonth: 4,
            taxIdentificationNumber: '114725897',
            primaryBranchName: 'Peliyagoda Depot',
            primaryBranchCode: 'PLY',
        ), $owner);
    }

    private function report(string $slug): void
    {
        $host = $slug.'.'.config('asids.tenancy.central_domain');
        $output = $this->command?->getOutput();

        $output?->writeln('');
        $output?->writeln('  <fg=green;options=bold>Demo workspace ready.</>');
        $output?->writeln("  URL       http://{$host}");
        $output?->writeln('  Password  '.self::DEMO_PASSWORD.'  <fg=gray>(all accounts)</>');
        $output?->writeln('');
        $output?->writeln('  <options=bold>Accounts</>');
        $output?->writeln('    owner@demo.test        Owner          — full control');
        $output?->writeln('    admin@demo.test        Administrator  — users, roles, companies');
        $output?->writeln('    accountant@demo.test   Accountant     — books and audit trail');
        $output?->writeln('    bookkeeper@demo.test   Bookkeeper     — day-to-day entry');
        $output?->writeln('    viewer@demo.test       Viewer         — read only');
        $output?->writeln('');
        $output?->writeln("  <fg=yellow>Add to /etc/hosts:</> 127.0.0.1 {$host}");
        $output?->writeln('');
    }
}
