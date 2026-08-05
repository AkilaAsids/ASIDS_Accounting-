<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Application\DTOs;

use Illuminate\Support\Str;

/**
 * Everything needed to stand up a new workspace.
 *
 * A readonly value object rather than a long parameter list, because provisioning
 * is called from three places — the public sign-up endpoint, the platform back
 * office, and the demo seeder — and a positional argument list of fourteen items
 * would eventually be called with two of them transposed.
 */
final readonly class ProvisionTenantData
{
    public function __construct(
        public string $tenantName,
        public string $slug,
        public string $ownerFirstName,
        public string $ownerEmail,
        public ?string $ownerLastName = null,
        public ?string $ownerPassword = null,
        public ?string $legalName = null,
        public ?string $companyName = null,
        public string $countryCode = 'LK',
        public string $currencyCode = 'LKR',
        public string $timezone = 'Asia/Colombo',
        public string $locale = 'en',
        public ?string $planCode = null,
        public ?int $trialDays = 14,
        public ?string $contactPhone = null,
        public ?string $taxIdentificationNumber = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $name = trim((string) ($input['tenant_name'] ?? $input['name'] ?? ''));

        return new self(
            tenantName: $name,
            // A slug is derived when the caller does not supply one, so sign-up
            // needs one fewer field. Uniqueness is settled by the service, not
            // here: a DTO must not need a database.
            slug: strtolower(trim((string) ($input['slug'] ?? Str::slug($name)))),
            ownerFirstName: trim((string) ($input['owner_first_name'] ?? '')),
            ownerEmail: strtolower(trim((string) ($input['owner_email'] ?? ''))),
            ownerLastName: self::nullableString($input['owner_last_name'] ?? null),
            ownerPassword: self::nullableString($input['owner_password'] ?? null),
            legalName: self::nullableString($input['legal_name'] ?? null),
            companyName: self::nullableString($input['company_name'] ?? null),
            countryCode: strtoupper((string) ($input['country_code'] ?? config('asids.regional.default_country', 'LK'))),
            currencyCode: strtoupper((string) ($input['currency_code'] ?? config('asids.regional.default_currency', 'LKR'))),
            timezone: (string) ($input['timezone'] ?? config('asids.regional.default_timezone', 'Asia/Colombo')),
            locale: (string) ($input['locale'] ?? config('asids.regional.default_locale', 'en')),
            planCode: self::nullableString($input['plan_code'] ?? null),
            trialDays: isset($input['trial_days']) ? (int) $input['trial_days'] : 14,
            contactPhone: self::nullableString($input['contact_phone'] ?? null),
            taxIdentificationNumber: self::nullableString($input['tax_identification_number'] ?? null),
        );
    }

    /**
     * The first company is named after the workspace unless told otherwise: a
     * single-company SME should not have to answer the same question twice.
     */
    public function resolvedCompanyName(): string
    {
        return $this->companyName ?? $this->tenantName;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
