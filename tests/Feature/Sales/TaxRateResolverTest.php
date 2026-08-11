<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Application\Services\TaxRateResolver;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Exceptions\AmbiguousTaxRate;
use Asids\Core\Sales\Domain\Exceptions\NoApplicableTaxRate;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which rate applied on a given day, and what tax that produces.
 *
 * Stage 3 of Milestone 3, and the most consequential tests in the module. Every invoice line's tax comes
 * from this resolver: pick the wrong row and the invoice is wrong, the ledger posts a wrong liability,
 * and the VAT return misstates what is owed — all of it balancing, none of it detectable downstream.
 *
 * So the boundaries are tested one day at a time rather than in the middle of a range, because off-by-one
 * is the failure this code is most likely to have and the least likely to be noticed.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->owner = $this->acme['owner'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->outputVat = Account::query()
        ->forCompany($this->company->getKey())
        ->where('code', '2140')
        ->firstOrFail();

    $this->codes = app(TaxCodeService::class);
    $this->resolver = app(TaxRateResolver::class);
});

/**
 * A charging VAT range. Named distinctly from the Stage 2 helpers, because Pest helpers are global and a
 * collision kills the whole suite rather than one file.
 */
function vatRange(string $rate, string $from, ?string $to = null, string $code = 'VAT'): TaxCode
{
    return test()->codes->create(test()->company, new TaxCodeData(
        code: $code,
        name: 'Value Added Tax',
        taxType: TaxType::Vat,
        rate: $rate,
        effectiveFrom: CarbonImmutable::parse($from),
        effectiveTo: $to === null ? null : CarbonImmutable::parse($to),
        outputAccountId: (string) test()->outputVat->getKey(),
    ));
}

function resolveOn(string $date, string $code = 'VAT'): TaxCode
{
    return test()->resolver->resolve(test()->company, $code, CarbonImmutable::parse($date));
}

function lkr(string $amount): Money
{
    return Money::of($amount, 'LKR');
}

describe('date boundaries', function (): void {
    beforeEach(function (): void {
        vatRange('18', '2026-01-01', '2026-06-30');
    });

    it('refuses the day before the range starts', function (): void {
        // One day out, not one month. Off-by-one is the failure this code is most prone to.
        expect(fn () => resolveOn('2025-12-31'))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('resolves on the exact first day', function (): void {
        expect(resolveOn('2026-01-01')->rate)->toBe('18.0000');
    });

    it('resolves in the middle of the range', function (): void {
        expect(resolveOn('2026-03-15')->rate)->toBe('18.0000');
    });

    it('resolves on the exact last day', function (): void {
        // Inclusive at the upper end, matching the database's `daterange(..., '[]')`. If the two disagreed,
        // a document dated the 30th would resolve differently from what the constraint believes.
        expect(resolveOn('2026-06-30')->rate)->toBe('18.0000');
    });

    it('refuses the day after the range ends', function (): void {
        expect(fn () => resolveOn('2026-07-01'))
            ->toThrow(NoApplicableTaxRate::class);
    });
});

describe('historical, current and future rates', function (): void {
    beforeEach(function (): void {
        vatRange('15', '2025-01-01', '2025-12-31');
        vatRange('18', '2026-01-01', '2026-06-30');
        vatRange('20', '2026-07-01');
    });

    it('resolves the historical rate for a past date', function (): void {
        // The reason rates are effective-dated at all: an invoice corrected months later must resolve the
        // rate that applied on its own date, never today's.
        expect(resolveOn('2025-06-01')->rate)->toBe('15.0000');
    });

    it('resolves the rate that applied mid-history', function (): void {
        expect(resolveOn('2026-02-01')->rate)->toBe('18.0000');
    });

    it('resolves the open-ended rate for a current date', function (): void {
        expect(resolveOn('2026-08-01')->rate)->toBe('20.0000');
    });

    it('resolves the open-ended rate far into the future', function (): void {
        // Open-ended means open-ended. A rate with no end date governs every later date until someone ends
        // it, which is exactly how a current rate should behave.
        expect(resolveOn('2035-01-01')->rate)->toBe('20.0000');
    });

    it('picks by date rather than by newest row', function (): void {
        // The distinction that matters. "Newest" is right for today and wrong for the case rates exist for.
        expect(resolveOn('2025-06-01')->rate)->toBe('15.0000')
            ->and(resolveOn('2026-08-01')->rate)->toBe('20.0000');
    });

    it('refuses a date before every range', function (): void {
        expect(fn () => resolveOn('2024-12-31'))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('resolves a future-dated range only within its own dates', function (): void {
        vatRange('25', '2027-01-01', null, 'FUTURE');

        expect(resolveOn('2027-06-01', 'FUTURE')->rate)->toBe('25.0000')
            ->and(fn () => resolveOn('2026-12-31', 'FUTURE'))->toThrow(NoApplicableTaxRate::class);
    });
});

describe('gaps', function (): void {
    it('refuses a date falling in a gap between ranges', function (): void {
        vatRange('18', '2026-01-01', '2026-06-30');
        vatRange('20', '2026-08-01');

        // July is uncovered. The mistake a user makes by ending one range and forgetting to open the next,
        // and the resolver must not paper over it.
        expect(fn () => resolveOn('2026-07-15'))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('says the code exists but the date is uncovered', function (): void {
        vatRange('18', '2026-01-01', '2026-06-30');

        try {
            resolveOn('2026-09-01');
            expect()->fail('an uncovered date should have been refused');
        } catch (NoApplicableTaxRate $exception) {
            // Distinguished from "no such code": something exists to correct rather than to create, and the
            // message has to say so or the user goes looking for a missing code.
            expect($exception->problemCode())->toBe('tax-rate-date-not-covered')
                ->and($exception->getMessage())->toContain('gap');
        }
    });

    it('resolves either side of a gap', function (): void {
        vatRange('18', '2026-01-01', '2026-06-30');
        vatRange('20', '2026-08-01');

        expect(resolveOn('2026-06-30')->rate)->toBe('18.0000')
            ->and(resolveOn('2026-08-01')->rate)->toBe('20.0000');
    });
});

describe('never guessing', function (): void {
    it('refuses rather than defaulting to zero when no code exists', function (): void {
        try {
            resolveOn('2026-03-01', 'NOSUCH');
            expect()->fail('a missing tax code should have been refused');
        } catch (NoApplicableTaxRate $exception) {
            // A silent zero would produce an invoice that looks right, posts a balanced entry, ties in the
            // trial balance, and understates a VAT return. Nothing downstream could detect it.
            expect($exception->problemCode())->toBe('no-applicable-tax-rate');
        }
    });

    it('does not fall back to another code', function (): void {
        $this->codes->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        // A missing VAT is not quietly served by a zero-rated code that happens to exist.
        expect(fn () => resolveOn('2026-03-01', 'VAT'))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('refuses an inactive rate and says it is inactive', function (): void {
        $taxCode = vatRange('18', '2026-01-01');
        $this->codes->deactivate($taxCode);

        try {
            resolveOn('2026-03-01');
            expect()->fail('an inactive rate should have been refused');
        } catch (NoApplicableTaxRate $exception) {
            // Separated from "not found" because the remedies differ: one is reactivated, the other created.
            expect($exception->problemCode())->toBe('tax-rate-inactive');
        }
    });

    it('ignores a soft-deleted rate', function (): void {
        $taxCode = vatRange('18', '2026-01-01');
        $this->codes->delete($taxCode);

        // A deleted rate never applied to anything, and the exclusion constraint has already released its
        // dates — so it must not be resolvable.
        expect(fn () => resolveOn('2026-03-01'))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('resolves the live rate while a deleted one covers the same dates', function (): void {
        $deleted = vatRange('18', '2026-01-01');
        $this->codes->delete($deleted);
        vatRange('20', '2026-01-01');

        expect(resolveOn('2026-03-01')->rate)->toBe('20.0000');
    });

    it('raises rather than choosing when two rates cover one date', function (): void {
        $taxCode = vatRange('18', '2026-01-01', '2026-06-30');

        // Planted by bypassing the service *and* the constraint, which is the only way to reach this state:
        // the exclusion constraint makes it impossible through any normal path. Dropped here for the length
        // of the test to prove the guard behind it works.
        DB::statement('ALTER TABLE tax_codes DROP CONSTRAINT tax_codes_no_overlapping_rates');

        DB::table('tax_codes')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $taxCode->tenant_id,
            'company_id' => $taxCode->company_id,
            'code' => 'VAT',
            'name' => 'Duplicate',
            'tax_type' => TaxType::Vat->value,
            'rate' => '20.0000',
            'output_account_id' => $this->outputVat->getKey(),
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `first()` here would let the query planner decide a company's tax rate, so two invoices on the
        // same day could be taxed differently with no error anywhere.
        try {
            resolveOn('2026-03-01');
            expect()->fail('two covering rates should have been refused');
        } catch (AmbiguousTaxRate $exception) {
            expect($exception->problemCode())->toBe('ambiguous-tax-rate')
                // A 500, not a 409: no input the caller could change would fix it.
                ->and($exception->problemStatus())->toBe(500)
                ->and($exception->getMessage())->toContain('2 rates');
        }
    });
});

describe('company and tenant isolation', function (): void {
    it('resolves within the company that owns the code', function (): void {
        vatRange('18', '2026-01-01');

        expect(resolveOn('2026-03-01')->company_id)->toBe($this->company->getKey());
    });

    it('does not resolve another company’s code', function (): void {
        vatRange('18', '2026-01-01');

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        // Same workspace, same tenant_id, so row level security is satisfied by either company's rows —
        // only the company predicate keeps them apart.
        expect(fn () => $this->resolver->resolve($second, 'VAT', CarbonImmutable::parse('2026-03-01')))
            ->toThrow(NoApplicableTaxRate::class);
    });

    it('resolves each company’s own rate for the same code', function (): void {
        vatRange('18', '2026-01-01');

        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $this->codes->create($second, new TaxCodeData(
            code: 'VAT',
            name: 'VAT',
            taxType: TaxType::Vat,
            rate: '20',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            outputAccountId: (string) Account::query()
                ->forCompany((string) $second->getKey())
                ->where('code', '2140')
                ->firstOrFail()
                ->getKey(),
        ));

        expect(resolveOn('2026-03-01')->rate)->toBe('18.0000')
            ->and($this->resolver->resolve($second, 'VAT', CarbonImmutable::parse('2026-03-01'))->rate)
            ->toBe('20.0000');
    });

    it('cannot resolve a code belonging to another tenant', function (): void {
        vatRange('18', '2026-01-01');
        $acmeCompany = $this->company;

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        // Acme's company object still in hand, but the policy hides its rows entirely — so even a caller
        // holding the right identifiers gets nothing.
        expect(fn () => $this->resolver->resolve($acmeCompany, 'VAT', CarbonImmutable::parse('2026-03-01')))
            ->toThrow(NoApplicableTaxRate::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('tax_codes'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});

describe('applying a rate through Money', function (): void {
    it('converts a stored percentage to a multiplication factor', function (): void {
        $taxCode = vatRange('18', '2026-01-01');

        // The conversion happens once, exactly, with bcdiv. In binary floating point 18.0/100 is not 0.18,
        // and the error would survive into every tax amount the ledger stored.
        expect($taxCode->rateFactor())->toBe('0.1800000000');
    });

    it('computes tax at eighteen percent', function (): void {
        $taxCode = vatRange('18', '2026-01-01');

        expect($this->resolver->applyTo(lkr('1000.00'), $taxCode)->toDecimalString())->toBe('180.0000');
    });

    it('computes nothing at zero percent', function (): void {
        $taxCode = $this->codes->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        expect($this->resolver->applyTo(lkr('1000.00'), $taxCode)->isZero())->toBeTrue();
    });

    it('computes tax at a fractional percentage', function (): void {
        $taxCode = vatRange('2.5', '2026-01-01');

        expect($this->resolver->applyTo(lkr('1000.00'), $taxCode)->toDecimalString())->toBe('25.0000');
    });

    it('computes tax at one hundred percent', function (): void {
        $taxCode = vatRange('100', '2026-01-01');

        expect($this->resolver->applyTo(lkr('1000.00'), $taxCode)->toDecimalString())->toBe('1000.0000');
    });

    it('keeps four-decimal rates exact', function (): void {
        $taxCode = vatRange('18.1234', '2026-01-01');

        // A percentage to four places needs six as a fraction, and bcdiv at scale 10 covers it — so the
        // factor is exact rather than merely close.
        expect($taxCode->rateFactor())->toBe('0.1812340000')
            ->and($this->resolver->applyTo(lkr('1000.00'), $taxCode)->toDecimalString())->toBe('181.2340');
    });

    it('rounds half away from zero at the ledger’s scale', function (): void {
        $taxCode = vatRange('18', '2026-01-01');

        // 3.33 * 0.18 = 0.5994 exactly, which needs no rounding at scale 4 — the check is that no float
        // error creeps in and turns it into 0.5993 or 0.5995.
        expect($this->resolver->applyTo(lkr('3.33'), $taxCode)->toDecimalString())->toBe('0.5994');
    });

    it('rounds a half up rather than to even', function (): void {
        $taxCode = vatRange('50', '2026-01-01');

        // 0.0001 at 50% is 0.00005, exactly half of the ledger's smallest unit. Half away from zero gives
        // 0.0001; banker's rounding would give 0.0000. The documented behaviour is the former, and a tax
        // authority expects it.
        expect($this->resolver->applyTo(lkr('0.0001'), $taxCode)->toDecimalString())->toBe('0.0001');
    });

    it('rounds to the currency’s precision when asked', function (): void {
        $taxCode = vatRange('18', '2026-01-01');

        // 33.33 * 0.18 = 5.9994. Rounded to two places for a document presented in rupees and cents, that
        // is 6.00 — which is what makes a printed invoice add up.
        expect($this->resolver->applyTo(lkr('33.33'), $taxCode, precision: 2)->toDecimalString())
            ->toBe('6.0000');
    });

    it('leaves the ledger scale intact when no precision is asked for', function (): void {
        $taxCode = vatRange('18', '2026-01-01');

        expect($this->resolver->applyTo(lkr('33.33'), $taxCode)->toDecimalString())->toBe('5.9994');
    });

    it('shows no floating-point drift across many applications', function (): void {
        $taxCode = vatRange('18', '2026-01-01');

        // The regression a float implementation would fail. A hundred applications of 0.18 to 0.07,
        // accumulated, must land exactly on the arithmetic total.
        $total = lkr('0.00');

        for ($i = 0; $i < 100; $i++) {
            $total = $total->plus($this->resolver->applyTo(lkr('0.07'), $taxCode));
        }

        // 0.07 * 0.18 = 0.0126 exactly, a hundred times is 1.2600.
        expect($total->toDecimalString())->toBe('1.2600');
    });

    it('resolves and applies in one call', function (): void {
        vatRange('18', '2026-01-01', '2026-06-30');
        vatRange('20', '2026-07-01');

        // The convenience callers will use, and it must honour the date rather than the newest row.
        expect($this->resolver->taxOn(lkr('1000.00'), $this->company, 'VAT', CarbonImmutable::parse('2026-03-01'))->toDecimalString())
            ->toBe('180.0000')
            ->and($this->resolver->taxOn(lkr('1000.00'), $this->company, 'VAT', CarbonImmutable::parse('2026-08-01'))->toDecimalString())
            ->toBe('200.0000');
    });

    it('refuses to compute when no rate resolves', function (): void {
        expect(fn () => $this->resolver->taxOn(lkr('1000.00'), $this->company, 'VAT', CarbonImmutable::parse('2026-03-01')))
            ->toThrow(NoApplicableTaxRate::class);
    });
});

describe('the tax types', function (): void {
    it('applies VAT at its configured rate', function (): void {
        $taxCode = vatRange('18', '2026-01-01');

        expect($this->resolver->applyTo(lkr('1000.00'), $taxCode)->toDecimalString())->toBe('180.0000')
            ->and($taxCode->tax_type)->toBe(TaxType::Vat);
    });

    it('applies SVAT at zero while recognising the regime', function (): void {
        $taxCode = $this->codes->create($this->company, new TaxCodeData(
            code: 'SVAT',
            name: 'Suspended VAT',
            taxType: TaxType::Svat,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        // The one legitimate zero: explicitly configured, not a fallback. The suspended-payment mechanics
        // are a later phase; resolution simply returns what was configured.
        expect(resolveOn('2026-03-01', 'SVAT')->tax_type)->toBe(TaxType::Svat)
            ->and($this->resolver->applyTo(lkr('1000.00'), $taxCode)->isZero())->toBeTrue();
    });

    it('applies zero-rated at nothing while staying inside the VAT system', function (): void {
        $taxCode = $this->codes->create($this->company, new TaxCodeData(
            code: 'ZERO',
            name: 'Zero rated export',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        expect($this->resolver->applyTo(lkr('5000.00'), $taxCode)->isZero())->toBeTrue()
            ->and($taxCode->tax_type->isWithinVatSystem())->toBeTrue();
    });

    it('applies exempt at nothing and stays outside the VAT system', function (): void {
        $taxCode = $this->codes->create($this->company, new TaxCodeData(
            code: 'EXEMPT',
            name: 'Exempt supply',
            taxType: TaxType::Exempt,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        // Exempt and zero-rated both add nothing to an invoice and are not the same thing — a return
        // reports them separately, which is why the distinction survives to here.
        expect($this->resolver->applyTo(lkr('5000.00'), $taxCode)->isZero())->toBeTrue()
            ->and($taxCode->tax_type->isWithinVatSystem())->toBeFalse();
    });

    it('resolves each type independently by its own code', function (): void {
        vatRange('18', '2026-01-01');
        $this->codes->create($this->company, new TaxCodeData(
            code: 'EXEMPT',
            name: 'Exempt',
            taxType: TaxType::Exempt,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        expect(resolveOn('2026-03-01', 'VAT')->tax_type)->toBe(TaxType::Vat)
            ->and(resolveOn('2026-03-01', 'EXEMPT')->tax_type)->toBe(TaxType::Exempt);
    });
});

describe('code matching', function (): void {
    it('resolves regardless of the case asked for', function (): void {
        vatRange('18', '2026-01-01');

        // Case-insensitive, matching the exclusion constraint's `upper(code)`. A lookup treating `vat` and
        // `VAT` as different would miss the very row the database considers a duplicate.
        expect(resolveOn('2026-03-01', 'vat')->rate)->toBe('18.0000')
            ->and(resolveOn('2026-03-01', 'Vat')->rate)->toBe('18.0000');
    });

    it('ignores surrounding whitespace', function (): void {
        vatRange('18', '2026-01-01');

        expect(resolveOn('2026-03-01', '  VAT  ')->rate)->toBe('18.0000');
    });
});
