<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Support;

use Asids\Core\Platform\Domain\Contracts\CompliancePackContract;

/**
 * Jurisdiction-neutral compliance pack.
 *
 * Bound as the default so that Phase 1 — which provisions companies but posts no
 * transactions — has a working implementation without pretending to encode
 * statutory rules that have not been reviewed by an accountant yet. Every
 * validation method accepts any non-empty value rather than silently rejecting
 * legitimate input, and the registration list is the generic shape the
 * `companies` table already supports.
 *
 * The Sri Lankan pack replaces this via config('asids.regional.compliance_packs')
 * without any change to calling code.
 */
final class NullCompliancePack implements CompliancePackContract
{
    public function countryCode(): string
    {
        return (string) config('asids.regional.default_country', 'LK');
    }

    public function displayName(): string
    {
        return 'Generic (no statutory rules applied)';
    }

    public function defaultCurrency(): string
    {
        return (string) config('asids.regional.default_currency', 'LKR');
    }

    public function defaultFiscalYearStartMonth(): int
    {
        return 1;
    }

    public function isValidTaxIdentificationNumber(string $value): bool
    {
        return trim($value) !== '';
    }

    public function isValidNationalIdentityNumber(string $value): bool
    {
        return trim($value) !== '';
    }

    /**
     * @return array<string, string>
     */
    public function registrationFields(): array
    {
        return [
            'registration_number' => 'Company registration number',
            'tax_identification_number' => 'Tax identification number',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedTaxRegimes(): array
    {
        return [];
    }
}
