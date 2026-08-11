<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the database refuses about a tax code.
 *
 * Stage 1 of Milestone 3, and these tests exist separately from the service tests that will follow
 * because they assert a different thing: that the rules hold when the service is not involved. Every
 * insert here goes through the query builder, bypassing the model, the DTO and the service — so a
 * passing test means the constraint is doing the work rather than the application being polite.
 *
 * That distinction earns its keep in a product where a bulk import, a data fix or a future service can
 * all write to this table. A rule enforced only in `TaxCodeService` is a rule that holds until the
 * second writer arrives.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->company = $this->acme['company'];
    $this->tenantId = $this->acme['tenant']->getKey();

    app(ChartTemplateService::class)->apply($this->company);
});

/**
 * The company's output VAT account.
 *
 * A real account from the chart rather than a random uuid: the column has a foreign key, and using the
 * account these codes will genuinely post to keeps the fixtures honest about what a tax code looks
 * like in service.
 */
function accountId(?string $companyId = null): string
{
    return (string) Account::query()
        ->forCompany($companyId ?? (string) test()->company->getKey())
        ->where('code', '2140')
        ->firstOrFail()
        ->getKey();
}

/**
 * A raw row, with only what the caller wants to vary overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function taxRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenantId,
        'company_id' => test()->company->getKey(),
        'code' => 'VAT',
        'name' => 'Value Added Tax',
        'tax_type' => TaxType::Vat->value,
        'rate' => '18.0000',
        // Nullable in the schema; supplied here because a non-zero rate requires it, which is one of
        // the constraints under test.
        'output_account_id' => null,
        'is_active' => true,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

function insertTax(array $overrides = []): void
{
    DB::table('tax_codes')->insert(taxRow($overrides));
}

describe('the rate is a percentage', function (): void {
    it('accepts a rate of zero', function (): void {
        insertTax(['rate' => '0.0000', 'tax_type' => TaxType::ZeroRated->value]);

        expect(DB::table('tax_codes')->count())->toBe(1);
    });

    it('accepts a fractional percentage', function (): void {
        insertTax(['rate' => '2.5000', 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->value('rate'))->toBe('2.5000');
    });

    it('accepts one hundred percent', function (): void {
        insertTax(['rate' => '100.0000', 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->value('rate'))->toBe('100.0000');
    });

    it('refuses a rate above one hundred', function (): void {
        // The bound is not decoration. A rate entered as a fraction of 100 — 1800 for 18% — would
        // otherwise multiply every invoice by eighteen.
        expect(fn () => insertTax(['rate' => '100.0001', 'output_account_id' => accountId()]))
            ->toThrow(QueryException::class);
    });

    it('refuses a rate entered as basis points', function (): void {
        expect(fn () => insertTax(['rate' => '1800.0000', 'output_account_id' => accountId()]))
            ->toThrow(QueryException::class);
    });

    it('refuses a negative rate', function (): void {
        expect(fn () => insertTax(['rate' => '-1.0000']))
            ->toThrow(QueryException::class);
    });
});

describe('the type and the rate have to agree', function (): void {
    it('refuses a rate on an exempt code', function (): void {
        // Exempt and zero-rated charge nothing by definition, and a code misclassified as one while
        // carrying a rate puts tax on an invoice that should have none.
        expect(fn () => insertTax([
            'tax_type' => TaxType::Exempt->value,
            'rate' => '18.0000',
            'output_account_id' => accountId(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses a rate on a zero-rated code', function (): void {
        expect(fn () => insertTax([
            'tax_type' => TaxType::ZeroRated->value,
            'rate' => '5.0000',
            'output_account_id' => accountId(),
        ]))->toThrow(QueryException::class);
    });

    it('accepts SVAT at zero', function (): void {
        insertTax(['tax_type' => TaxType::Svat->value, 'rate' => '0.0000']);

        expect(DB::table('tax_codes')->value('tax_type'))->toBe('svat');
    });

    it('refuses a tax type outside the enum', function (): void {
        expect(fn () => insertTax(['tax_type' => 'gst', 'rate' => '0.0000']))
            ->toThrow(QueryException::class);
    });
});

describe('a charging rate needs somewhere to post', function (): void {
    it('refuses a non-zero rate with no output account', function (): void {
        expect(fn () => insertTax(['rate' => '18.0000', 'output_account_id' => null]))
            ->toThrow(QueryException::class);
    });

    it('accepts a non-zero rate with an output account', function (): void {
        insertTax(['rate' => '18.0000', 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->count())->toBe(1);
    });

    it('accepts a zero rate with no output account', function (): void {
        // Nothing posts, so nothing is needed to post it to.
        insertTax(['rate' => '0.0000', 'tax_type' => TaxType::Exempt->value, 'output_account_id' => null]);

        expect(DB::table('tax_codes')->count())->toBe(1);
    });
});

describe('the effective range', function (): void {
    it('accepts an open-ended range', function (): void {
        insertTax(['effective_to' => null, 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->value('effective_to'))->toBeNull();
    });

    it('accepts a closed range', function (): void {
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30', 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->count())->toBe(1);
    });

    it('accepts a single-day range', function (): void {
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => '2026-01-01', 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->count())->toBe(1);
    });

    it('refuses a range that ends before it starts', function (): void {
        expect(fn () => insertTax([
            'effective_from' => '2026-06-30',
            'effective_to' => '2026-01-01',
            'output_account_id' => accountId(),
        ]))->toThrow(QueryException::class);
    });
});

describe('one rate per code per day', function (): void {
    it('accepts consecutive non-overlapping ranges for the same code', function (): void {
        // The intended shape of a rate change: close the old range, open the next one the following day.
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30', 'rate' => '18.0000', 'output_account_id' => accountId()]);
        insertTax(['effective_from' => '2026-07-01', 'effective_to' => null, 'rate' => '20.0000', 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->count())->toBe(2);
    });

    it('refuses two ranges that overlap', function (): void {
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30', 'output_account_id' => accountId()]);

        expect(fn () => insertTax([
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-12-31',
            'rate' => '20.0000',
            'output_account_id' => accountId(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses ranges that meet on a single day', function (): void {
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30', 'output_account_id' => accountId()]);

        // Inclusive at both ends on purpose: a document dated the 30th would otherwise have two
        // candidate rates and resolution would not be deterministic.
        expect(fn () => insertTax([
            'effective_from' => '2026-06-30',
            'effective_to' => '2026-12-31',
            'rate' => '20.0000',
            'output_account_id' => accountId(),
        ]))->toThrow(QueryException::class);
    });

    it('refuses a later range while an open-ended one exists', function (): void {
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => null, 'output_account_id' => accountId()]);

        // A NULL end is unbounded, so ending the previous row is a required step in adding a new rate
        // rather than something a caller can forget.
        expect(fn () => insertTax([
            'effective_from' => '2026-07-01',
            'effective_to' => null,
            'rate' => '20.0000',
            'output_account_id' => accountId(),
        ]))->toThrow(QueryException::class);
    });

    it('treats codes differing only in case as the same code', function (): void {
        insertTax(['code' => 'VAT', 'output_account_id' => accountId()]);

        // Every other code column in the platform is case-insensitive; a `vat` alongside a `VAT` would
        // be two codes to the database and one to every human.
        expect(fn () => insertTax(['code' => 'vat', 'rate' => '20.0000', 'output_account_id' => accountId()]))
            ->toThrow(QueryException::class);
    });

    it('allows a different code to share the same range', function (): void {
        insertTax(['code' => 'VAT', 'output_account_id' => accountId()]);
        insertTax(['code' => 'EXEMPT', 'tax_type' => TaxType::Exempt->value, 'rate' => '0.0000', 'output_account_id' => null]);

        expect(DB::table('tax_codes')->count())->toBe(2);
    });

    it('allows another company the same code and range', function (): void {
        insertTax(['code' => 'VAT', 'output_account_id' => accountId()]);

        $second = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Second Books'),
            $this->acme['owner'],
        );
        app(ChartTemplateService::class)->apply($second);

        // The exclusion constraint keys on company_id, so two companies registered for VAT at different
        // rates are not in conflict — which they must not be, since each keeps its own books.
        insertTax([
            'code' => 'VAT',
            'company_id' => $second->getKey(),
            'rate' => '20.0000',
            'output_account_id' => accountId((string) $second->getKey()),
        ]);

        expect(DB::table('tax_codes')->count())->toBe(2);
    });

    it('frees the range once a row is soft-deleted', function (): void {
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => null, 'output_account_id' => accountId()]);
        DB::table('tax_codes')->update(['deleted_at' => now()]);

        // Restricted to live rows: a deleted code must not reserve its dates for ever.
        insertTax(['effective_from' => '2026-01-01', 'effective_to' => null, 'rate' => '20.0000', 'output_account_id' => accountId()]);

        expect(DB::table('tax_codes')->whereNull('deleted_at')->count())->toBe(1);
    });
});

describe('tenant isolation enforced by the database', function (): void {
    it('hides another workspace’s tax codes from raw SQL', function (): void {
        insertTax(['output_account_id' => accountId()]);

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(DB::table('tax_codes')->count())->toBe(0);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('tax_codes'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );

    it('refuses a write naming another tenant', function (): void {
        $acmeTenantId = $this->tenantId;
        $acmeCompanyId = $this->company->getKey();

        $globex = $this->createWorkspace('globex');
        $this->withinTenant($globex['tenant']);

        expect(fn () => DB::table('tax_codes')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $acmeTenantId,
            'company_id' => $acmeCompanyId,
            'code' => 'SNEAK',
            'name' => 'Planted',
            'tax_type' => TaxType::Exempt->value,
            'rate' => '0.0000',
            'is_active' => true,
            'effective_from' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    })->skip(
        fn () => ! RowLevelSecurity::isEnforced('tax_codes'),
        'Row level security is not in force for the connecting role. Connect as a NOBYPASSRLS role '
        .'(asids_app). This test would otherwise pass without exercising anything.',
    );
});
