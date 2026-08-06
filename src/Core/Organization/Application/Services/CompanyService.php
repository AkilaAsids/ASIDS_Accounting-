<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Application\Services;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Domain\Contracts\LedgerActivityProbe;
use Asids\Core\Organization\Domain\Enums\OrganizationStatus;
use Asids\Core\Organization\Domain\Events\CompanyArchived;
use Asids\Core\Organization\Domain\Events\CompanyCreated;
use Asids\Core\Organization\Domain\Exceptions\AccountingConfigurationLocked;
use Asids\Core\Organization\Domain\Exceptions\CannotArchive;
use Asids\Core\Organization\Domain\Exceptions\CompanyLimitReached;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Domain\Contracts\CompliancePackContract;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Company lifecycle.
 *
 * Creation is atomic across three things, because a company without any of them is not
 * usable: the company row, its primary branch, and a membership for whoever created it.
 * The last of those is easy to overlook and produces the most confusing possible outcome —
 * an administrator creates a company and then cannot open it.
 */
final readonly class CompanyService
{
    public function __construct(
        private TenantContext $tenantContext,
        private BranchService $branches,
        private MembershipService $memberships,
        private LedgerActivityProbe $ledger,
        private CompliancePackContract $compliance,
    ) {}

    public function create(CreateCompanyData $data, User $creator): Company
    {
        $tenant = $this->tenantContext->require();

        $this->assertWithinCompanyLimit();
        $this->assertRegistrationsValid($data);

        $company = DB::transaction(function () use ($data, $creator, $tenant): Company {
            $company = new Company();

            $company->fill([
                'name' => $data->name,
                'legal_name' => $data->legalName,
                'code' => $this->uniqueCode($data->derivedCode()),
                'slug' => $this->uniqueSlug($data->derivedSlug()),
                'registration_number' => $data->registrationNumber,
                'tax_identification_number' => $data->taxIdentificationNumber,
                'vat_registration_number' => $data->vatRegistrationNumber,
                'svat_registration_number' => $data->svatRegistrationNumber,
                // Registration flags are derived from whether a number was supplied rather
                // than trusted from the client: a company claiming VAT registration with no
                // number would produce invoices that fail an IRD audit.
                'is_vat_registered' => $data->vatRegistrationNumber !== null,
                'is_svat_registered' => $data->svatRegistrationNumber !== null,
                'business_type' => $data->businessType,
                'industry' => $data->industry,
                'base_currency_code' => $data->baseCurrencyCode,
                'fiscal_year_start_month' => $data->fiscalYearStartMonth,
                'fiscal_year_start_day' => $data->fiscalYearStartDay,
                'currency_precision' => $data->currencyPrecision,
                'country_code' => $data->countryCode,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
                'email' => $data->email,
                'phone' => $data->phone,
                'website' => $data->website,
                'address_line_1' => $data->addressLine1,
                'address_line_2' => $data->addressLine2,
                'city' => $data->city,
                'district' => $data->district,
                'postal_code' => $data->postalCode,
            ]);

            $company->status = OrganizationStatus::Active;
            $company->created_by_id = $creator->getKey();

            // The first company in a workspace is the default whether or not the caller
            // asked, so a new workspace always has somewhere to land.
            $company->is_default = $data->isDefault || ! $this->tenantHasCompany();

            // Only one company per workspace may be default (a partial unique index
            // enforces it), so an explicit request to create a second default demotes the
            // incumbent rather than failing with a constraint violation.
            if ($company->is_default) {
                Company::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $company->save();

            $this->branches->createPrimary(
                company: $company,
                name: $data->primaryBranchName,
                code: $data->primaryBranchCode,
            );

            // Platform staff hold no memberships by design, so creating a company as staff
            // (back-office provisioning) must not attempt to grant one.
            if (! $creator->is_platform_admin) {
                $this->memberships->grant(
                    company: $company,
                    user: $creator,
                    grantedBy: $creator,
                    makeDefault: $company->is_default,
                );
            }

            return $company;
        });

        CompanyCreated::dispatch($company, $creator);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Company $company, array $attributes, User $actor): Company
    {
        $this->assertAccountingConfigurationUnchangedOrMutable($company, $attributes);

        return DB::transaction(function () use ($company, $attributes): Company {
            $company->fill($attributes);

            // Kept consistent with the numbers rather than accepted from the client, for
            // the same reason as on create.
            if (array_key_exists('vat_registration_number', $attributes)) {
                $company->is_vat_registered = $company->vat_registration_number !== null;
            }

            if (array_key_exists('svat_registration_number', $attributes)) {
                $company->is_svat_registered = $company->svat_registration_number !== null;
            }

            // The database has a check constraint asserting SVAT implies VAT; clearing VAT
            // must therefore clear SVAT, or the save fails with an opaque SQL error.
            if (! $company->is_vat_registered) {
                $company->is_svat_registered = false;
                $company->svat_registration_number = null;
            }

            $company->save();

            return $company;
        });
    }

    /**
     * Close a company to further activity.
     *
     * Never a delete: a company that has appeared on a financial statement must remain
     * resolvable for as long as the records are retained.
     */
    public function archive(Company $company, User $actor): Company
    {
        if ($company->is_default) {
            throw CannotArchive::defaultCompany($company->name);
        }

        if ($this->activeCompanyCount() <= 1) {
            throw CannotArchive::lastActiveCompany($company->name);
        }

        $archived = DB::transaction(function () use ($company): Company {
            // Both columns move together; the table's check constraint requires it.
            $company->status = OrganizationStatus::Archived;
            $company->archived_at = now();
            $company->save();

            // Memberships are revoked so the company disappears from every user's switcher
            // and from `accessibleCompanyIds()`. Revocation is timestamped, so restoring the
            // company later is an administrative regrant rather than a data recovery.
            $this->memberships->revokeAllForCompany($company);

            return $company;
        });

        CompanyArchived::dispatch($archived, $actor);

        return $archived;
    }

    public function restore(Company $company, User $actor): Company
    {
        if ($company->isActive()) {
            return $company;
        }

        $this->assertWithinCompanyLimit();

        $company->status = OrganizationStatus::Active;
        $company->archived_at = null;
        $company->save();

        return $company;
    }

    /**
     * Move the workspace default to another company.
     *
     * Two writes that must not be separable: a partial application would leave the
     * workspace with two defaults or none, and the partial unique index would reject the
     * second write anyway — leaving the first committed.
     */
    public function makeDefault(Company $company): Company
    {
        if (! $company->isActive()) {
            throw BusinessRuleViolation::make(
                code: 'archived-company-cannot-be-default',
                message: 'An archived company cannot be the workspace default.',
            );
        }

        return DB::transaction(function () use ($company): Company {
            Company::query()
                ->where('is_default', true)
                ->whereKeyNot($company->getKey())
                ->update(['is_default' => false]);

            $company->is_default = true;
            $company->save();

            return $company;
        });
    }

    // ── Invariants ──────────────────────────────────────────────────────────

    private function assertWithinCompanyLimit(): void
    {
        $tenant = $this->tenantContext->require();
        $limit = $tenant->companyLimit();

        if ($this->activeCompanyCount() >= $limit) {
            throw CompanyLimitReached::at($limit);
        }
    }

    /**
     * Base currency and fiscal calendar are immutable once the books have activity.
     *
     * Checked against the *incoming* attributes rather than after filling the model, so a
     * request that merely echoes the current values back — which is what a form submission
     * does — is not mistaken for an attempt to change them.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertAccountingConfigurationUnchangedOrMutable(Company $company, array $attributes): void
    {
        $currencyChanging = array_key_exists('base_currency_code', $attributes)
            && strtoupper((string) $attributes['base_currency_code']) !== $company->base_currency_code;

        $calendarChanging =
            (array_key_exists('fiscal_year_start_month', $attributes)
                && (int) $attributes['fiscal_year_start_month'] !== $company->fiscal_year_start_month)
            || (array_key_exists('fiscal_year_start_day', $attributes)
                && (int) $attributes['fiscal_year_start_day'] !== $company->fiscal_year_start_day);

        if (! $currencyChanging && ! $calendarChanging) {
            return;
        }

        if (! $this->ledger->companyHasActivity($company)) {
            return;
        }

        if ($currencyChanging) {
            throw AccountingConfigurationLocked::baseCurrency($company->base_currency_code);
        }

        throw AccountingConfigurationLocked::fiscalCalendar(
            $this->ledger->earliestActivityDate($company)?->format('Y-m-d')
        );
    }

    /**
     * Statutory identifiers are validated by the jurisdiction's compliance pack, so a
     * malformed TIN is rejected at entry rather than at filing time — which in Sri Lanka
     * means rejected by RAMIS weeks later.
     */
    private function assertRegistrationsValid(CreateCompanyData $data): void
    {
        if ($data->taxIdentificationNumber !== null
            && ! $this->compliance->isValidTaxIdentificationNumber($data->taxIdentificationNumber)) {
            throw BusinessRuleViolation::make(
                code: 'invalid-tax-identification-number',
                message: 'That tax identification number is not valid for the selected country.',
                context: ['country_code' => $data->countryCode],
            );
        }

        // SVAT is a suspended-VAT scheme; it presupposes VAT registration, and the database
        // enforces the same rule.
        if ($data->svatRegistrationNumber !== null && $data->vatRegistrationNumber === null) {
            throw BusinessRuleViolation::make(
                code: 'svat-requires-vat',
                message: 'A company registered for SVAT must also hold a VAT registration number.',
            );
        }
    }

    // ── Uniqueness helpers ──────────────────────────────────────────────────

    /**
     * Codes and slugs are unique per tenant. A numeric suffix is appended rather than
     * failing, because two group companies named "Acme Lanka" and "Acme Logistics" both
     * derive "AL" and the customer should not have to invent a code to get past a form.
     */
    private function uniqueCode(string $base): string
    {
        return $this->uniqueValue(
            base: $base,
            exists: static fn (string $candidate): bool => Company::query()
                ->withTrashed()
                ->whereRaw('upper(code) = ?', [strtoupper($candidate)])
                ->exists(),
            maxLength: 24,
        );
    }

    private function uniqueSlug(string $base): string
    {
        return $this->uniqueValue(
            base: $base,
            exists: static fn (string $candidate): bool => Company::query()
                ->withTrashed()
                ->whereRaw('lower(slug) = ?', [strtolower($candidate)])
                ->exists(),
            maxLength: 120,
        );
    }

    /**
     * @param  callable(string): bool  $exists
     */
    private function uniqueValue(string $base, callable $exists, int $maxLength): string
    {
        $base = mb_substr($base, 0, $maxLength);

        if (! $exists($base)) {
            return $base;
        }

        // Bounded at 999: beyond that the derivation is clearly wrong and looping further
        // would hide the real problem behind a slow request.
        for ($suffix = 2; $suffix <= 999; $suffix++) {
            $suffixString = (string) $suffix;
            $candidate = mb_substr($base, 0, $maxLength - mb_strlen($suffixString) - 1).'-'.$suffixString;

            if (! $exists($candidate)) {
                return $candidate;
            }
        }

        throw BusinessRuleViolation::make(
            code: 'company-identifier-exhausted',
            message: 'Could not generate a unique identifier for this company. Please supply a code explicitly.',
        );
    }

    private function activeCompanyCount(): int
    {
        return Company::query()->active()->count();
    }

    private function tenantHasCompany(): bool
    {
        return Company::query()->exists();
    }
}
