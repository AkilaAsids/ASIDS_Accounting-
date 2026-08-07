<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Application\Services;

use Asids\Core\Authorization\Application\Services\RoleProvisioner;
use Asids\Core\Identity\Application\DTOs\CreateUserData;
use Asids\Core\Identity\Application\Services\UserService;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Tenancy\Application\DTOs\ProvisionTenantData;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Domain\Enums\TenantStatus;
use Asids\Core\Tenancy\Domain\Events\TenantProvisioned;
use Asids\Core\Tenancy\Domain\Models\Domain;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stands up a complete, immediately usable workspace.
 *
 * "Immediately usable" is the requirement that shapes this class. A tenant with no
 * company, no roles and no owner is not a workspace a customer can sign in to, so
 * provisioning is atomic across all five concerns: tenant, hostname, roles, owner,
 * first company. A partial provision is worse than a failed one — the customer
 * cannot sign in and cannot retry, because the slug is taken.
 *
 * LAYERING NOTE
 * -------------
 * This service depends on the application services of Identity, Authorization and
 * Organization, which is the one place in the codebase where Tenancy depends
 * *outward*. That is deliberate and documented in
 * docs/adr/0005-workspace-provisioning-ownership.md: workspace provisioning is a
 * use case that spans every core module, and it has to live somewhere. Those
 * modules never depend on this class in return — they depend only on Tenancy's
 * domain layer (TenantContext, BelongsToTenant), so there is no cycle.
 */
final readonly class TenantProvisioningService
{
    public function __construct(
        private TenantRepositoryContract $tenants,
        private RoleProvisioner $roles,
        private UserService $users,
        private CompanyService $companies,
        private TenantContext $context,
    ) {}

    /**
     * @return array{tenant: Tenant, owner: User, temporary_password: string|null}
     */
    public function provision(ProvisionTenantData $data): array
    {
        $this->guardSlug($data->slug);

        // Provisioning writes across several tenants' worth of tables before any
        // tenant context exists, so row level security is suspended for the
        // duration — and only for the duration.
        $result = RowLevelSecurity::bypass(fn (): array => DB::transaction(function () use ($data): array {
            $tenant = $this->createTenant($data);
            $this->createPrimaryDomain($tenant);

            // Roles must exist before the owner is created, because the owner is
            // assigned the owner role as part of creation.
            $ownerRole = $this->roles->provisionSystemRolesFor($tenant);

            // From here on the work is inside the tenant, so context is
            // established and the ordinary scopes and policies apply.
            [$owner, $temporaryPassword] = $this->context->runFor($tenant, function () use ($data, $ownerRole): array {
                $password = $data->ownerPassword ?? Str::password(16);

                $owner = $this->users->create(new CreateUserData(
                    firstName: $data->ownerFirstName,
                    lastName: $data->ownerLastName,
                    email: $data->ownerEmail,
                    password: $password,
                    roleIds: [$ownerRole->getKey()],
                    timezone: $data->timezone,
                    locale: $data->locale,
                    // The owner is active immediately: there is nobody else to
                    // approve them, and the sign-up flow already proved control of
                    // the address.
                    activateImmediately: true,
                    mustChangePassword: $data->ownerPassword === null,
                ));

                $company = $this->companies->create(new CreateCompanyData(
                    name: $data->resolvedCompanyName(),
                    legalName: $data->legalName,
                    baseCurrencyCode: $data->currencyCode,
                    countryCode: $data->countryCode,
                    timezone: $data->timezone,
                    locale: $data->locale,
                    taxIdentificationNumber: $data->taxIdentificationNumber,
                    isDefault: true,
                ), $owner);

                $this->users->setDefaultCompany($owner, $company);

                return [$owner, $data->ownerPassword === null ? $password : null];
            });

            $tenant->status = TenantStatus::Active;
            $tenant->provisioned_at = now();
            $this->tenants->save($tenant);

            return [
                'tenant' => $tenant,
                'owner' => $owner,
                'temporary_password' => $temporaryPassword,
            ];
        }));

        // Dispatched after the transaction commits, so a listener that sends the
        // welcome e-mail can never reference a workspace that was rolled back.
        TenantProvisioned::dispatch($result['tenant'], $result['owner']);

        return $result;
    }

    public function suspend(Tenant $tenant, string $reason): Tenant
    {
        if ($tenant->status === TenantStatus::Suspended) {
            return $tenant;
        }

        $tenant->status = TenantStatus::Suspended;
        $tenant->suspended_at = now();
        $tenant->suspension_reason = $reason;

        return $this->tenants->save($tenant);
    }

    public function activate(Tenant $tenant): Tenant
    {
        if ($tenant->status === TenantStatus::Cancelled) {
            throw BusinessRuleViolation::make(
                code: 'cancelled-workspace-not-reactivatable',
                message: 'A closed workspace cannot be reactivated. Restore it from backup instead.',
            );
        }

        $tenant->status = TenantStatus::Active;
        $tenant->suspended_at = null;
        $tenant->suspension_reason = null;

        return $this->tenants->save($tenant);
    }

    private function createTenant(ProvisionTenantData $data): Tenant
    {
        $tenant = new Tenant;

        $tenant->fill([
            'name' => $data->tenantName,
            'slug' => $data->slug,
            'legal_name' => $data->legalName,
            'plan_code' => $data->planCode,
            'country_code' => $data->countryCode,
            'currency_code' => $data->currencyCode,
            'timezone' => $data->timezone,
            'locale' => $data->locale,
            'contact_name' => trim($data->ownerFirstName.' '.($data->ownerLastName ?? '')),
            'contact_email' => $data->ownerEmail,
            'contact_phone' => $data->contactPhone,
            'tax_identification_number' => $data->taxIdentificationNumber,
        ]);

        $tenant->status = TenantStatus::Provisioning;

        if ($data->trialDays !== null && $data->trialDays > 0) {
            $tenant->trial_ends_at = now()->addDays($data->trialDays);
        }

        return $this->tenants->save($tenant);
    }

    private function createPrimaryDomain(Tenant $tenant): Domain
    {
        $domain = new Domain;

        $domain->fill([
            'tenant_id' => $tenant->getKey(),
            'domain' => $tenant->slug.'.'.config('asids.tenancy.central_domain'),
            'is_primary' => true,
            'is_custom' => false,
        ]);

        $domain->save();

        return $domain;
    }

    private function guardSlug(string $slug): void
    {
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $slug) !== 1) {
            throw BusinessRuleViolation::make(
                code: 'invalid-workspace-slug',
                message: 'A workspace address may contain only lowercase letters, numbers and hyphens, and must start and end with a letter or number.',
            );
        }

        /** @var list<string> $reserved */
        $reserved = config('asids.tenancy.reserved_slugs', []);

        if (in_array($slug, $reserved, true)) {
            throw BusinessRuleViolation::make(
                code: 'reserved-workspace-slug',
                message: 'That workspace address is reserved. Please choose another.',
            );
        }

        if ($this->tenants->slugExists($slug)) {
            throw ResourceConflict::duplicate('workspace', 'address', $slug);
        }
    }
}
