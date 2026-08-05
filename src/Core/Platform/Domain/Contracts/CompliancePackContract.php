<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Domain\Contracts;

/**
 * A country's statutory rules, isolated behind one interface.
 *
 * ASIDS launches into Sri Lanka and will follow into Australia, Singapore, the
 * UAE and India. Statutory logic — tax identifier formats, filing calendars,
 * payroll contributions, invoice numbering mandates — is the part of an
 * accounting system that differs most between jurisdictions and changes most
 * often within one. Keeping it behind a contract resolved per company means
 * entering a new market is a new implementation of this interface, not a
 * refactor of the ledger.
 *
 * Phase 1 defines the seam and ships NullCompliancePack. The Sri Lankan pack (VAT,
 * SVAT, TIN validation, EPF/ETF, PAYE/APIT, RAMIS and e-invoicing readiness)
 * lands with the accounting phases that need it.
 */
interface CompliancePackContract
{
    /**
     * ISO 3166-1 alpha-2 country this pack governs.
     */
    public function countryCode(): string;

    public function displayName(): string;

    /**
     * ISO 4217 code of the country's default reporting currency.
     */
    public function defaultCurrency(): string;

    /**
     * Month (1-12) in which the statutory fiscal year begins. Sri Lanka's
     * assessment year starts in April, so this is not universally January.
     */
    public function defaultFiscalYearStartMonth(): int;

    /**
     * Validate a taxpayer identification number's format.
     *
     * Format validation only — never a claim that the number is registered.
     */
    public function isValidTaxIdentificationNumber(string $value): bool;

    /**
     * Validate a national identity number's format, where the jurisdiction has
     * one that payroll depends upon.
     */
    public function isValidNationalIdentityNumber(string $value): bool;

    /**
     * Statutory registrations a company in this jurisdiction may hold, keyed by
     * the `companies` column that stores them.
     *
     * @return array<string, string>
     */
    public function registrationFields(): array;

    /**
     * Tax regimes the pack can compute, by machine name.
     *
     * @return list<string>
     */
    public function supportedTaxRegimes(): array;
}
