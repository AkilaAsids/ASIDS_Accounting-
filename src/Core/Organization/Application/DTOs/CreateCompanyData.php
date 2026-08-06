<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Application\DTOs;

use Illuminate\Support\Str;

/**
 * Input for creating a company.
 *
 * The accounting configuration carries defaults rather than being required, because a
 * single-company SME signing up should not have to answer questions about fiscal
 * calendars before they can send an invoice. The defaults come from the tenant's regional
 * settings and can be corrected later — until the books have activity, at which point
 * CompanyService locks them.
 */
final readonly class CreateCompanyData
{
    public function __construct(
        public string $name,
        public ?string $legalName = null,
        /**
         * Short uppercase identifier used on document numbers (INV-ACME-0001). Derived
         * from the name when absent.
         */
        public ?string $code = null,
        public string $baseCurrencyCode = 'LKR',
        public string $countryCode = 'LK',
        public string $timezone = 'Asia/Colombo',
        public string $locale = 'en',
        public int $fiscalYearStartMonth = 1,
        public int $fiscalYearStartDay = 1,
        public int $currencyPrecision = 2,
        public ?string $registrationNumber = null,
        public ?string $taxIdentificationNumber = null,
        public ?string $vatRegistrationNumber = null,
        public ?string $svatRegistrationNumber = null,
        public ?string $businessType = null,
        public ?string $industry = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $district = null,
        public ?string $postalCode = null,
        /**
         * True only for a workspace's first company, set by provisioning. Everything else
         * becomes the default by an explicit administrator action.
         */
        public bool $isDefault = false,
        /**
         * Name for the primary branch created alongside the company. Every company must
         * have one, so this is a naming choice, not an optional extra.
         */
        public string $primaryBranchName = 'Head Office',
        public string $primaryBranchCode = 'HO',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $name = trim((string) ($input['name'] ?? ''));

        return new self(
            name: $name,
            legalName: self::nullableString($input['legal_name'] ?? null),
            code: self::nullableString($input['code'] ?? null),
            baseCurrencyCode: strtoupper((string) ($input['base_currency_code'] ?? config('asids.regional.default_currency', 'LKR'))),
            countryCode: strtoupper((string) ($input['country_code'] ?? config('asids.regional.default_country', 'LK'))),
            timezone: (string) ($input['timezone'] ?? config('asids.regional.default_timezone', 'Asia/Colombo')),
            locale: (string) ($input['locale'] ?? config('asids.regional.default_locale', 'en')),
            fiscalYearStartMonth: (int) ($input['fiscal_year_start_month'] ?? 1),
            fiscalYearStartDay: (int) ($input['fiscal_year_start_day'] ?? 1),
            currencyPrecision: (int) ($input['currency_precision'] ?? 2),
            registrationNumber: self::nullableString($input['registration_number'] ?? null),
            taxIdentificationNumber: self::nullableString($input['tax_identification_number'] ?? null),
            vatRegistrationNumber: self::nullableString($input['vat_registration_number'] ?? null),
            svatRegistrationNumber: self::nullableString($input['svat_registration_number'] ?? null),
            businessType: self::nullableString($input['business_type'] ?? null),
            industry: self::nullableString($input['industry'] ?? null),
            email: self::nullableString($input['email'] ?? null),
            phone: self::nullableString($input['phone'] ?? null),
            website: self::nullableString($input['website'] ?? null),
            addressLine1: self::nullableString($input['address_line_1'] ?? null),
            addressLine2: self::nullableString($input['address_line_2'] ?? null),
            city: self::nullableString($input['city'] ?? null),
            district: self::nullableString($input['district'] ?? null),
            postalCode: self::nullableString($input['postal_code'] ?? null),
            primaryBranchName: self::nullableString($input['primary_branch_name'] ?? null) ?? 'Head Office',
            primaryBranchCode: strtoupper(self::nullableString($input['primary_branch_code'] ?? null) ?? 'HO'),
        );
    }

    /**
     * A code derived from the name: first letters of each word, or the first characters of
     * a single word, uppercased. Uniqueness is settled by CompanyService, which is where
     * the database lives.
     */
    public function derivedCode(): string
    {
        if ($this->code !== null && $this->code !== '') {
            return strtoupper($this->code);
        }

        $words = preg_split('/\s+/', $this->name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initials = count($words) > 1
            ? implode('', array_map(static fn (string $word): string => mb_substr($word, 0, 1), $words))
            : $this->name;

        // Non-alphanumerics are stripped rather than transliterated: the code appears in
        // document numbers, where punctuation would break sorting and file names.
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $initials) ?? '');

        return $code === '' ? 'CO' : mb_substr($code, 0, 12);
    }

    public function derivedSlug(): string
    {
        $slug = Str::slug($this->name);

        return $slug === '' ? 'company' : mb_substr($slug, 0, 100);
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
