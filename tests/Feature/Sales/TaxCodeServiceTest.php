<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Platform\Domain\Contracts\CompliancePackContract;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The tax-code service: lifecycle, validation and the rules the database cannot state.
 *
 * Stage 2 of Milestone 3. The schema tests alongside this file prove what PostgreSQL refuses; these
 * prove what the application refuses *before* the database has to, and the two rules only the
 * application can enforce at all — that an account belongs to the same company, and that it is of the
 * right type.
 *
 * The account-type rule is the one worth stating plainly. A CHECK constraint cannot join to `accounts`,
 * so the database can require that a charging rate has an output account but not that the account is a
 * liability. Point output VAT at revenue and the trial balance still ties while the return understates
 * what is owed — wrong and invisible together, which is the combination worth testing hardest.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->outputVat = taxAccount('2140');
    $this->inputVat = taxAccount('1170');
    $this->revenue = taxAccount('4100');

    $this->service = app(TaxCodeService::class);
});

function taxAccount(string $code, ?string $companyId = null): Account
{
    return Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * A VAT code at the given rate, posting to output VAT.
 */
function vatData(string $rate = '18', string $code = 'VAT', string $from = '2026-01-01', ?string $to = null): TaxCodeData
{
    return new TaxCodeData(
        code: $code,
        name: 'Value Added Tax',
        taxType: TaxType::Vat,
        rate: $rate,
        effectiveFrom: CarbonImmutable::parse($from),
        effectiveTo: $to === null ? null : CarbonImmutable::parse($to),
        outputAccountId: (string) test()->outputVat->getKey(),
    );
}

/**
 * Rebinds the usage probe so the immutability rule can be exercised before documents exist.
 */
function withRateApplied(bool $applied = true): void
{
    app()->bind(TaxRateUsageProbe::class, fn (): TaxRateUsageProbe => new class($applied) implements TaxRateUsageProbe
    {
        public function __construct(private bool $applied) {}

        public function hasBeenApplied(TaxCode $taxCode): bool
        {
            return $this->applied;
        }
    });

    // The service is a singleton holding the probe it was built with, so it has to be forgotten and
    // re-resolved for the new binding to reach it.
    app()->forgetInstance(TaxCodeService::class);
    test()->service = app(TaxCodeService::class);
}

/**
 * Rebinds the compliance pack so jurisdictional restriction can be exercised.
 *
 * @param  list<string>  $regimes
 */
function withSupportedRegimes(array $regimes): void
{
    app()->bind(CompliancePackContract::class, fn (): CompliancePackContract => new class($regimes) implements CompliancePackContract
    {
        /** @param list<string> $regimes */
        public function __construct(private array $regimes) {}

        public function countryCode(): string
        {
            return 'LK';
        }

        public function displayName(): string
        {
            return 'Sri Lanka';
        }

        public function defaultCurrency(): string
        {
            return 'LKR';
        }

        public function defaultFiscalYearStartMonth(): int
        {
            return 4;
        }

        public function isValidTaxIdentificationNumber(string $value): bool
        {
            return true;
        }

        public function isValidNationalIdentityNumber(string $value): bool
        {
            return true;
        }

        public function registrationFields(): array
        {
            return [];
        }

        /** @return list<string> */
        public function supportedTaxRegimes(): array
        {
            return $this->regimes;
        }
    });

    app()->forgetInstance(TaxCodeService::class);
    test()->service = app(TaxCodeService::class);
}

describe('creating a tax code', function (): void {
    it('creates a VAT code at a charging rate', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        // The rate is a percentage at every layer. 18, never 0.18.
        expect($taxCode->rate)->toBe('18.0000')
            ->and($taxCode->tax_type)->toBe(TaxType::Vat)
            ->and($taxCode->output_account_id)->toBe($this->outputVat->getKey())
            ->and($taxCode->is_active)->toBeTrue()
            ->and($taxCode->company_id)->toBe($this->company->getKey());
    });

    it('creates an SVAT code at zero', function (): void {
        $taxCode = $this->service->create($this->company, new TaxCodeData(
            code: 'SVAT',
            name: 'Suspended VAT',
            taxType: TaxType::Svat,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        // SVAT is recognised as a legitimate regime and resolves at whatever is configured — zero today.
        // Its suspended-payment mechanics are deliberately absent from this milestone.
        expect($taxCode->tax_type)->toBe(TaxType::Svat)
            ->and($taxCode->rate)->toBe('0.0000')
            ->and($taxCode->chargesTax())->toBeFalse();
    });

    it('creates a zero-rated code', function (): void {
        $taxCode = $this->service->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated export',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        // Zero-rated is taxable at 0% and stays inside the VAT system — the distinction a return depends
        // on, and the one most easily lost.
        expect($taxCode->tax_type->isWithinVatSystem())->toBeTrue()
            ->and($taxCode->output_account_id)->toBeNull();
    });

    it('creates an exempt code outside the VAT system', function (): void {
        $taxCode = $this->service->create($this->company, new TaxCodeData(
            code: 'EXEMPT',
            name: 'Exempt supply',
            taxType: TaxType::Exempt,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        expect($taxCode->tax_type->isWithinVatSystem())->toBeFalse();
    });

    it('accepts a fractional percentage', function (): void {
        $taxCode = $this->service->create($this->company, vatData('2.5'));

        expect($taxCode->rate)->toBe('2.5000');
    });

    it('accepts one hundred percent', function (): void {
        $taxCode = $this->service->create($this->company, vatData('100'));

        expect($taxCode->rate)->toBe('100.0000');
    });

    it('records who created it', function (): void {
        $taxCode = $this->service->create($this->company, vatData(), (string) $this->owner->getKey());

        expect($taxCode->created_by_id)->toBe($this->owner->getKey());
    });
});

describe('rate validation', function (): void {
    it('refuses a rate that is not a number', function (): void {
        expect(fn () => $this->service->create($this->company, vatData('eighteen')))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a negative rate', function (): void {
        expect(fn () => $this->service->create($this->company, vatData('-1')))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a rate above one hundred', function (): void {
        expect(fn () => $this->service->create($this->company, vatData('100.01')))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a rate entered as basis points, and says why', function (): void {
        try {
            $this->service->create($this->company, vatData('1800'));
            expect()->fail('1800 should have been refused as a percentage');
        } catch (BusinessRuleViolation $violation) {
            // The message has to teach the convention, because the mistake is silent otherwise: 1800
            // would multiply every invoice by eighteen.
            expect($violation->getMessage())->toContain('18')
                ->and($violation->getMessage())->toContain('not 1800');
        }
    });

    it('refuses a rate on an exempt code before the database has to', function (): void {
        expect(fn () => $this->service->create($this->company, new TaxCodeData(
            code: 'EXEMPT',
            name: 'Exempt',
            taxType: TaxType::Exempt,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a rate on a zero-rated code', function (): void {
        expect(fn () => $this->service->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated',
            taxType: TaxType::ZeroRated,
            rate: '5',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a blank code', function (): void {
        expect(fn () => $this->service->create($this->company, vatData('18', '   ')))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('account validation', function (): void {
    it('refuses a charging rate with no output account', function (): void {
        expect(fn () => $this->service->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'VAT',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a revenue account as the output account', function (): void {
        // The rule the database cannot express. Output tax credited to revenue leaves the trial balance
        // tying and the VAT return understated — wrong and invisible at once.
        try {
            $this->service->create($this->company, new TaxCodeData(
                code: 'VAT',
                name: 'VAT',
                taxType: TaxType::Vat,
                rate: '18',
                effectiveFrom: CarbonImmutable::parse('2026-01-01'),
                outputAccountId: (string) $this->revenue->getKey(),
            ));
            expect()->fail('a revenue account should not be accepted for output tax');
        } catch (BusinessRuleViolation $violation) {
            expect($violation->getMessage())->toContain('liability');
        }
    });

    it('refuses a non-postable header account', function (): void {
        $header = taxAccount('2100');

        expect(fn () => $this->service->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'VAT',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $header->getKey(),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses another company’s account', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $foreign = taxAccount('2140', (string) $second->getKey());

        // RLS cannot catch this: both companies share a tenant, so the policy is satisfied by either
        // one's accounts. Only the company comparison stops a tax code posting into its sibling's ledger.
        expect(fn () => $this->service->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'VAT',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $foreign->getKey(),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('accepts an asset account as the input account', function (): void {
        $taxCode = $this->service->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'VAT',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
            inputAccountId: (string) $this->inputVat->getKey(),
        ));

        expect($taxCode->input_account_id)->toBe($this->inputVat->getKey());
    });

    it('refuses a liability account as the input account', function (): void {
        // Input tax is a claim against the authority, so it belongs in an asset. Validated even though
        // sales never reads it, because a wrong account configured now is one purchasing inherits silently.
        expect(fn () => $this->service->create($this->company, new TaxCodeData(
            code: 'VAT',
            name: 'VAT',
            taxType: TaxType::Vat,
            rate: '18',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) $this->outputVat->getKey(),
            inputAccountId: (string) $this->outputVat->getKey(),
        )))->toThrow(BusinessRuleViolation::class);
    });
});

describe('compliance pack integration', function (): void {
    it('allows every regime when the pack declares no restriction', function (): void {
        // The load-bearing reading: NullCompliancePack returns [] because no pack has enumerated its
        // regimes yet, not because the country forbids all tax. Treating [] as deny-all would refuse
        // every tax code the product can currently create.
        withSupportedRegimes([]);

        $taxCode = $this->service->create($this->company, vatData('18'));

        expect($taxCode->tax_type)->toBe(TaxType::Vat);
    });

    it('refuses a regime the pack does not list', function (): void {
        withSupportedRegimes(['vat', 'exempt']);

        expect(fn () => $this->service->create($this->company, new TaxCodeData(
            code: 'SVAT',
            name: 'Suspended VAT',
            taxType: TaxType::Svat,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        )))->toThrow(BusinessRuleViolation::class);
    });

    it('allows a regime the pack does list', function (): void {
        withSupportedRegimes(['vat', 'exempt']);

        $taxCode = $this->service->create($this->company, vatData('18'));

        expect($taxCode->tax_type)->toBe(TaxType::Vat);
    });

    it('names the jurisdiction when refusing', function (): void {
        withSupportedRegimes(['vat']);

        try {
            $this->service->create($this->company, new TaxCodeData(
                code: 'SVAT',
                name: 'SVAT',
                taxType: TaxType::Svat,
                rate: '0',
                effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            ));
            expect()->fail('an unsupported regime should have been refused');
        } catch (BusinessRuleViolation $violation) {
            expect($violation->getMessage())->toContain('Sri Lanka');
        }
    });

    it('checks the regime on update as well as create', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        withSupportedRegimes(['vat']);

        expect(fn () => $this->service->update($taxCode, ['tax_type' => TaxType::Svat->value, 'rate' => '0']))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('effective ranges', function (): void {
    it('accepts an open-ended range', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        expect($taxCode->isOpenEnded())->toBeTrue();
    });

    it('refuses a range that ends before it starts', function (): void {
        expect(fn () => $this->service->create($this->company, vatData('18', 'VAT', '2026-06-30', '2026-01-01')))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('turns an overlap into a conflict rather than a database error', function (): void {
        $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01', '2026-06-30'));

        try {
            $this->service->create($this->company, vatData('20', 'VAT', '2026-06-01'));
            expect()->fail('the overlapping range should have been refused');
        } catch (ResourceConflict $conflict) {
            // The constraint stays the authority; only how its refusal reads changes. A caller must never
            // see a constraint name.
            expect($conflict->problemCode())->toBe('tax-code-range-overlaps')
                ->and($conflict->getMessage())->not->toContain('tax_codes_no_overlapping_rates')
                ->and($conflict->problemStatus())->toBe(409);
        }
    });

    it('supports the intended rate-change shape', function (): void {
        $original = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01'));

        $this->service->endRange($original, CarbonImmutable::parse('2026-06-30'));
        $successor = $this->service->create($this->company, vatData('20', 'VAT', '2026-07-01'));

        // Two rows, one code, no overlap — history preserved and the new rate current.
        expect($original->fresh()->effective_to->toDateString())->toBe('2026-06-30')
            ->and($successor->rate)->toBe('20.0000')
            ->and(TaxCode::query()->forCompany($this->company->getKey())->withCode('VAT')->count())->toBe(2);
    });

    it('refuses to end a range before it starts', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-06-01'));

        expect(fn () => $this->service->endRange($taxCode, CarbonImmutable::parse('2026-01-01')))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('reopens a closed range when effective_to is explicitly null', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01', '2026-06-30'));

        // The omitted-versus-null distinction, and why the update signature takes an array. Passing null
        // clears the end date; omitting the key would leave it in place, and a closed range would be
        // permanent.
        $this->service->update($taxCode, ['effective_to' => null]);

        expect($taxCode->fresh()->effective_to)->toBeNull();
    });

    it('leaves the end date alone when the key is omitted', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01', '2026-06-30'));

        $this->service->update($taxCode, ['name' => 'Renamed only']);

        expect($taxCode->fresh()->effective_to?->toDateString())->toBe('2026-06-30')
            ->and($taxCode->fresh()->name)->toBe('Renamed only');
    });
});

describe('updating', function (): void {
    it('changes the name and notes', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        $this->service->update($taxCode, ['name' => 'VAT (standard)', 'notes' => 'Confirmed with the auditor']);

        expect($taxCode->fresh()->name)->toBe('VAT (standard)')
            ->and($taxCode->fresh()->notes)->toBe('Confirmed with the auditor');
    });

    it('changes the rate while nothing has used it', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        $this->service->update($taxCode, ['rate' => '20']);

        expect($taxCode->fresh()->rate)->toBe('20.0000');
    });

    it('refuses an invalid rate on update', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        expect(fn () => $this->service->update($taxCode, ['rate' => '150']))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses a wrong-typed output account on update', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        expect(fn () => $this->service->update($taxCode, ['output_account_id' => (string) $this->revenue->getKey()]))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses another company’s account on update', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        expect(fn () => $this->service->update($taxCode, [
            'output_account_id' => (string) taxAccount('2140', (string) $second->getKey())->getKey(),
        ]))->toThrow(BusinessRuleViolation::class);
    });

    it('refuses raising a rate above zero with no account to post it to', function (): void {
        $taxCode = $this->service->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        expect(fn () => $this->service->update($taxCode, ['tax_type' => TaxType::Vat->value, 'rate' => '18']))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses an inverted range on update', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-06-01'));

        expect(fn () => $this->service->update($taxCode, ['effective_to' => '2026-01-01']))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('a rate that has been used cannot be rewritten', function (): void {
    it('refuses a rate change once applied to a document', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        withRateApplied();

        try {
            $this->service->update($taxCode->fresh(), ['rate' => '20']);
            expect()->fail('the rate change should have been refused');
        } catch (ResourceConflict $conflict) {
            // Change 18% to 20% on the row an invoice cited and that invoice's tax silently becomes
            // wrong, along with the return it was reported on.
            expect($conflict->problemCode())->toBe('tax-rate-already-applied')
                ->and($conflict->getMessage())->toContain('new range carrying the new rate');
        }
    });

    it('refuses moving effective_from once applied', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        withRateApplied();

        expect(fn () => $this->service->update($taxCode->fresh(), ['effective_from' => '2025-01-01']))
            ->toThrow(ResourceConflict::class);
    });

    it('still allows the name to change once applied', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        withRateApplied();

        // Immutability covers the accounting meaning, not the label. Freezing everything would stop a
        // company correcting a typo on a code it still uses.
        $this->service->update($taxCode->fresh(), ['name' => 'VAT (standard rate)']);

        expect($taxCode->fresh()->name)->toBe('VAT (standard rate)');
    });

    it('permits an identical rate to be resubmitted', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        withRateApplied();

        // A form that posts every field resubmits the current rate. Refusing that would make any edit
        // impossible, so the comparison is on value rather than on the key being present.
        $this->service->update($taxCode->fresh(), ['rate' => '18.0000', 'name' => 'Unchanged rate']);

        expect($taxCode->fresh()->name)->toBe('Unchanged rate');
    });

    it('permits closing the range once applied', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        withRateApplied();

        // Ending a range does not rewrite history — it records when the rate stopped applying, which is
        // exactly how a rate change is meant to be made.
        $this->service->endRange($taxCode->fresh(), CarbonImmutable::parse('2026-06-30'));

        expect($taxCode->fresh()->effective_to->toDateString())->toBe('2026-06-30');
    });

    it('refuses to delete a code that has been applied', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        withRateApplied();

        expect(fn () => $this->service->delete($taxCode->fresh()))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('the lifecycle', function (): void {
    it('deactivates and reactivates', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        $this->service->deactivate($taxCode);
        expect($taxCode->fresh()->is_active)->toBeFalse();

        $this->service->reactivate($taxCode);
        expect($taxCode->fresh()->is_active)->toBeTrue();
    });

    it('refuses to deactivate twice', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));
        $this->service->deactivate($taxCode);

        expect(fn () => $this->service->deactivate($taxCode->fresh()))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('refuses to reactivate an active code', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        expect(fn () => $this->service->reactivate($taxCode))
            ->toThrow(BusinessRuleViolation::class);
    });

    it('keeps the range when deactivated', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01'));
        $this->service->deactivate($taxCode);

        // An invoice already issued under this code must still resolve the rate it was charged, so
        // deactivating must not end the range.
        expect($taxCode->fresh()->effective_to)->toBeNull()
            ->and($taxCode->fresh()->effective_from->toDateString())->toBe('2026-01-01');
    });

    it('soft-deletes a code created in error', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        $this->service->delete($taxCode);

        expect(TaxCode::query()->find($taxCode->getKey()))->toBeNull()
            ->and(TaxCode::query()->withTrashed()->find($taxCode->getKey()))->not->toBeNull();
    });

    it('frees the range once soft-deleted', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01'));
        $this->service->delete($taxCode);

        $replacement = $this->service->create($this->company, vatData('20', 'VAT', '2026-01-01'));

        expect($replacement->rate)->toBe('20.0000');
    });

    it('restores a soft-deleted code whose range is still free', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01'));
        $this->service->delete($taxCode);

        $restored = $this->service->restore($taxCode);

        expect($restored->trashed())->toBeFalse();
    });

    it('refuses to restore when the range has been taken', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01'));
        $this->service->delete($taxCode);
        $this->service->create($this->company, vatData('20', 'VAT', '2026-01-01'));

        try {
            $this->service->restore($taxCode);
            expect()->fail('the restore should have conflicted');
        } catch (ResourceConflict $conflict) {
            expect($conflict->problemCode())->toBe('tax-code-range-taken-on-restore')
                ->and($conflict->getMessage())->not->toContain('tax_codes_no_overlapping_rates');
        }
    });

    it('restores alongside a non-overlapping range', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18', 'VAT', '2026-01-01', '2026-06-30'));
        $this->service->delete($taxCode);
        $this->service->create($this->company, vatData('20', 'VAT', '2026-07-01'));

        // The successor does not occupy the deleted row's dates, so restoring is legitimate — rate history
        // reassembled rather than blocked.
        $restored = $this->service->restore($taxCode);

        expect($restored->trashed())->toBeFalse()
            ->and(TaxCode::query()->forCompany($this->company->getKey())->withCode('VAT')->count())->toBe(2);
    });

    it('refuses to restore a code that was never deleted', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        expect(fn () => $this->service->restore($taxCode))
            ->toThrow(BusinessRuleViolation::class);
    });
});

describe('the audit trail', function (): void {
    it('records the old and new rate, not merely that rate changed', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        $this->service->update($taxCode, ['rate' => '20']);

        // The update entry specifically. Filtering only on the presence of `rate` would match the
        // creation entry too, whose `old_values` is null by definition — and a test that accidentally
        // asserted against creation would never notice a broken before-value on update.
        $entry = AuditLog::query()
            ->where('auditable_type', TaxCode::MORPH_ALIAS)
            ->where('auditable_id', $taxCode->getKey())
            ->get()
            ->first(fn (AuditLog $log): bool => $log->old_values !== null
                && array_key_exists('rate', $log->old_values));

        // The values, not the key. A test asserting only that `rate` appears would pass while the trail
        // recorded the wrong numbers — and the whole reason this model is audited is answering "changed
        // from what?".
        expect($entry)->not->toBeNull()
            ->and((string) json_encode($entry->old_values))->toContain('18.0000')
            ->and((string) json_encode($entry->new_values))->toContain('20.0000');
    });

    it('records the creation with its rate', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));

        $entries = AuditLog::query()
            ->where('auditable_type', TaxCode::MORPH_ALIAS)
            ->where('auditable_id', $taxCode->getKey())
            ->get();

        expect($entries)->not->toBeEmpty()
            ->and((string) json_encode($entries->pluck('new_values')))->toContain('18.0000');
    });

    it('records a deactivation', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));
        $this->service->deactivate($taxCode);

        $trail = (string) json_encode(
            AuditLog::query()->where('auditable_type', TaxCode::MORPH_ALIAS)->get()->pluck('new_values'),
        );

        expect($trail)->toContain('is_active');
    });

    it('records an account being repointed', function (): void {
        $taxCode = $this->service->create($this->company, vatData('18'));
        $other = taxAccount('2150');

        $this->service->update($taxCode, ['output_account_id' => (string) $other->getKey()]);

        $trail = (string) json_encode(
            AuditLog::query()->where('auditable_type', TaxCode::MORPH_ALIAS)->get()->pluck('new_values'),
        );

        // Repointing the output account changes which liability every future invoice's tax lands in —
        // invisible on an invoice, obvious on a balance sheet, so it belongs in the trail.
        expect($trail)->toContain($other->getKey());
    });
});

describe('tenant isolation enforced by the database', function (): void {
    it('hides another workspace’s tax codes from raw SQL', function (): void {
        $this->service->create($this->company, vatData('18'));

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('tax_codes')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('tax_codes'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('keeps a code lookup inside the workspace', function (): void {
        $this->service->create($this->company, vatData('18'));

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('tax_codes')->whereRaw("upper(code) = 'VAT'")->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('tax_codes'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});

describe('company isolation within a workspace', function (): void {
    it('keeps each company’s codes separate', function (): void {
        $this->service->create($this->company, vatData('18', 'VAT'));

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $secondCode = $this->service->create($second, new TaxCodeData(
            code: 'VAT',
            name: 'VAT',
            taxType: TaxType::Vat,
            rate: '20',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) taxAccount('2140', (string) $second->getKey())->getKey(),
        ));

        // Same code, same dates, different rates — legitimate, because each company keeps its own books
        // and may be registered differently.
        expect(TaxCode::query()->forCompany($this->company->getKey())->withCode('VAT')->first()->rate)->toBe('18.0000')
            ->and($secondCode->rate)->toBe('20.0000');
    });
});
