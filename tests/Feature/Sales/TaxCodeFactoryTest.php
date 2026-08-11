<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Sales\Application\Services\TaxRateResolver;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Exceptions\NoApplicableTaxRate;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * That `TaxCodeFactory` produces rows the database and the domain both accept.
 *
 * Written during the Stage 5 review because the factory was shipped unexercised, and an unexercised
 * factory is a liability rather than a convenience: Milestone 4 will build invoice fixtures on top of it,
 * and a factory that produces rows violating a CHECK or an exclusion constraint would surface as a
 * confusing failure inside invoice tests rather than here.
 *
 * The contract this pins down is that the factory needs a `company_id` and cannot invent one. That is
 * deliberate and matches `CustomerFactory`: a company carries a chart of accounts and a fiscal calendar,
 * and a factory conjuring one would produce a company no tax code could legitimately post through.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);

    $this->outputVat = Account::query()
        ->forCompany($this->company->getKey())
        ->where('code', '2140')
        ->firstOrFail();
});

it('produces a valid zero-rated code by default', function (): void {
    $taxCode = TaxCode::factory()->create(['company_id' => $this->company->getKey()]);

    // Zero-rated by default because a charging rate needs an output account, and the factory cannot invent
    // one belonging to the right company — the database would refuse the row.
    expect($taxCode->exists)->toBeTrue()
        ->and($taxCode->tax_type)->toBe(TaxType::ZeroRated)
        ->and($taxCode->rate)->toBe('0.0000')
        ->and($taxCode->output_account_id)->toBeNull()
        ->and($taxCode->is_active)->toBeTrue();
});

it('inherits the tenant from the active context', function (): void {
    $taxCode = TaxCode::factory()->create(['company_id' => $this->company->getKey()]);

    // `BelongsToTenant` supplies `tenant_id`, which is why the factory does not — and must not, since a
    // hardcoded tenant would defeat the isolation the trait exists to enforce.
    expect($taxCode->tenant_id)->toBe($this->acme['tenant']->getKey());
});

it('requires a company, and says so rather than inventing one', function (): void {
    // The contract, asserted so it is documented rather than discovered. `CustomerFactory` behaves
    // identically: a company owns a chart of accounts and a fiscal calendar, and a conjured one would be
    // a company no tax code could legitimately post through.
    expect(fn () => TaxCode::factory()->create())
        ->toThrow(QueryException::class);
});

it('produces codes that do not collide with each other', function (): void {
    $first = TaxCode::factory()->create(['company_id' => $this->company->getKey()]);
    $second = TaxCode::factory()->create(['company_id' => $this->company->getKey()]);

    // The reason the default code is random. The exclusion constraint keys on the code, so two default
    // instances sharing one would collide on their ranges — and the failure would look like a bug in
    // whatever test happened to create both.
    expect($second->code)->not->toBe($first->code)
        ->and(TaxCode::query()->forCompany($this->company->getKey())->count())->toBe(2);
});

it('produces a charging code the resolver can apply', function (): void {
    $taxCode = TaxCode::factory()
        ->charging('18', (string) $this->outputVat->getKey())
        ->create(['company_id' => $this->company->getKey()]);

    $tax = app(TaxRateResolver::class)->applyTo(
        Money::of('1000.00', 'LKR'),
        $taxCode,
    );

    // End to end: a factory-built row resolves and computes, which is what invoice fixtures will need.
    expect($taxCode->tax_type)->toBe(TaxType::Vat)
        ->and($taxCode->rate)->toBe('18.0000')
        ->and($tax->toDecimalString())->toBe('180.0000');
});

it('produces valid rows for every named state', function (string $state, TaxType $expectedType, bool $expectedActive): void {
    $taxCode = TaxCode::factory()->{$state}()->create(['company_id' => $this->company->getKey()]);

    expect($taxCode->exists)->toBeTrue()
        ->and($taxCode->tax_type)->toBe($expectedType)
        ->and($taxCode->is_active)->toBe($expectedActive)
        // Every non-charging state must be zero, or the type/rate CHECK would refuse the row.
        ->and($taxCode->rate)->toBe('0.0000');
})->with([
    ['svat', TaxType::Svat, true],
    ['exempt', TaxType::Exempt, true],
    ['inactive', TaxType::ZeroRated, false],
]);

it('produces an effective-dated range the resolver honours', function (): void {
    $taxCode = TaxCode::factory()
        ->charging('15', (string) $this->outputVat->getKey())
        ->effective('2025-01-01', '2025-12-31')
        ->create(['company_id' => $this->company->getKey()]);

    $resolver = app(TaxRateResolver::class);

    expect($resolver->resolve($this->company, $taxCode->code, CarbonImmutable::parse('2025-06-01'))->rate)
        ->toBe('15.0000')
        ->and(fn () => $resolver->resolve($this->company, $taxCode->code, CarbonImmutable::parse('2026-06-01')))
        ->toThrow(NoApplicableTaxRate::class);
});
