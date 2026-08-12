<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Sales\Application\DTOs\TaxCodeData;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Enums\TaxType;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * The tax-code HTTP surface — Lane B of the Sales HTTP API (`docs/SALES-HTTP-API-DESIGN.md` §5).
 *
 * Written test-first, before the routes, controller, form requests or resource exist: this suite is
 * the acceptance contract Lane B is built against, not a description of what is there today. Every
 * request below targets a URI or a route name the design names explicitly, so today a 404 means
 * exactly "not built yet".
 *
 * The harness mirrors `tests/Feature/Accounting/AccountingApiTest.php` and the authorization split
 * that test proves for the chart of accounts — `accounting.accounts.manage` is accountant-only, and
 * `sales.tax-codes.manage` (`RoleTemplate.php:104-105`) is the exact same shape: a bookkeeper reads
 * the rates an invoice will charge but does not decide what they are. That is the one place this
 * module's authorization split differs from `CustomerApiTest.php`, where both templates manage.
 *
 * A trap worth naming because two of the isolation tests below were originally written straight into
 * it: every undefined route in this application 404s with the identical generic
 * `{"type": ".../not-found", ...}` body and an empty `data`. A cross-tenant isolation assertion phrased
 * only as "the response does not contain the foreign row" or "the response is a 404" is satisfied by
 * that generic 404 today for the *wrong* reason, before any route exists. Each such assertion below is
 * gated behind a positive check that only a genuinely working endpoint can satisfy, so a pass is never
 * accidental.
 *
 * Helper names below are deliberately distinct from every other Sales test file's helpers
 * (`TaxCodeServiceTest.php`'s `withRateApplied()`/`vatData()`, `CustomerApiTest.php`'s equivalents,
 * etc.) because Pest loads every matched file into one process, and two files declaring the same
 * top-level function name would be a fatal error the moment both are run together.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'tax-acct@acme.test']);
    $this->bookkeeper = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', ['email' => 'tax-book@acme.test']);
    $this->viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer', ['email' => 'tax-view@acme.test']);

    $memberships = app(MembershipService::class);
    foreach ([$this->accountant, $this->bookkeeper, $this->viewer] as $member) {
        $memberships->grant($this->company, $member, $this->owner);
    }

    app(ChartTemplateService::class)->apply($this->company);

    $this->outputVat = Account::query()->forCompany($this->company->getKey())->where('code', '2140')->firstOrFail();
    $this->inputVat = Account::query()->forCompany($this->company->getKey())->where('code', '1170')->firstOrFail();
    // A liability heading: right type, not postable — for the account-not-postable case.
    $this->liabilityHeading = Account::query()->forCompany($this->company->getKey())->where('code', '2100')->firstOrFail();
});

/**
 * A member of the acme company, acting on its tax codes.
 */
function asTaxCodeApi(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $fresh = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($fresh ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->withHeader('X-Company', test()->company->getKey())
        ->json($method, $uri, $payload);
}

function taxCodeUri(string $suffix = ''): string
{
    $base = '/api/v1/companies/'.test()->company->getKey().'/tax-codes';

    return $suffix === '' ? $base : $base.'/'.ltrim($suffix, '/');
}

/**
 * A charging, VAT-style `TaxCodeData`, posting to the given output account (the company's Output VAT
 * Payable account, 2140, by default).
 */
function chargingTaxCodeData(
    string $code = 'VAT',
    string $rate = '18',
    string $from = '2026-01-01',
    ?string $to = null,
    ?string $outputAccountId = null,
): TaxCodeData {
    return new TaxCodeData(
        code: $code,
        name: 'Value Added Tax',
        taxType: TaxType::Vat,
        rate: $rate,
        effectiveFrom: CarbonImmutable::parse($from),
        effectiveTo: $to === null ? null : CarbonImmutable::parse($to),
        outputAccountId: $outputAccountId ?? (string) test()->outputVat->getKey(),
    );
}

/**
 * A zero-rated tax code built through the real service, for tests that only need a code to exist and
 * do not care about its rate.
 *
 * @param  array{code?: string}  $overrides
 */
function createTaxCode(array $overrides = []): TaxCode
{
    return app(TaxCodeService::class)->create(test()->company, new TaxCodeData(
        code: $overrides['code'] ?? 'TAX-'.random_int(1000, 9999),
        name: 'Zero Rated Supply',
        taxType: TaxType::ZeroRated,
        rate: '0',
        effectiveFrom: CarbonImmutable::parse('2026-01-01'),
    ));
}

/**
 * Rebinds the rate-usage probe so the immutability and in-use rules can be exercised over HTTP before
 * Milestone 4 binds a real implementation — the technique `TaxCodeServiceTest::withRateApplied()` uses
 * at the service layer, under a distinct name because both files load in the same test run.
 */
function withTaxRateApplied(bool $applied = true): void
{
    app()->bind(TaxRateUsageProbe::class, fn (): TaxRateUsageProbe => new class($applied) implements TaxRateUsageProbe
    {
        public function __construct(private bool $applied) {}

        public function hasBeenApplied(TaxCode $taxCode): bool
        {
            return $this->applied;
        }
    });

    app()->forgetInstance(TaxCodeService::class);
}

describe('route names', function (): void {
    it('registers every tax-code route under the api.v1.companies.tax-codes name', function (): void {
        foreach ([
            'index', 'store', 'show', 'update', 'destroy',
            'end-range', 'deactivate', 'reactivate', 'restore',
        ] as $action) {
            expect(Route::has("api.v1.companies.tax-codes.{$action}"))
                ->toBeTrue("expected route api.v1.companies.tax-codes.{$action} to be registered");
        }
    });
});

describe('authorization', function (): void {
    it('lets an accountant create a tax code', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'ZERO', 'name' => 'Zero Rated Supply', 'tax_type' => 'zero_rated',
            'rate' => '0', 'effective_from' => '2026-01-01',
        ]);

        expect($response)->toBeEnvelope(201);
    });

    it('refuses a bookkeeper the right to create a tax code', function (): void {
        $response = asTaxCodeApi($this->bookkeeper, 'POST', taxCodeUri(), [
            'code' => 'ZERO', 'name' => 'Zero Rated Supply', 'tax_type' => 'zero_rated',
            'rate' => '0', 'effective_from' => '2026-01-01',
        ]);

        // The split that matters for this module: a bookkeeper reads the rates an invoice will
        // charge (`sales.tax-codes.view`) but `sales.tax-codes.manage` belongs to the accountant
        // template alone (RoleTemplate.php:104-105, :146).
        expect($response)->toBeProblem('forbidden', 403);
    });

    it('refuses a viewer the right to create a tax code', function (): void {
        $response = asTaxCodeApi($this->viewer, 'POST', taxCodeUri(), [
            'code' => 'ZERO', 'name' => 'Zero Rated Supply', 'tax_type' => 'zero_rated',
            'rate' => '0', 'effective_from' => '2026-01-01',
        ]);

        expect($response)->toBeProblem('forbidden', 403);
    });

    it('lets a bookkeeper and a viewer read tax codes', function (): void {
        $taxCode = createTaxCode(['code' => 'READABLE']);

        expect(asTaxCodeApi($this->bookkeeper, 'GET', taxCodeUri())->getStatusCode())->toBe(200)
            ->and(asTaxCodeApi($this->viewer, 'GET', taxCodeUri((string) $taxCode->getKey()))->getStatusCode())->toBe(200);
    });

    it('refuses a bookkeeper every write action on an existing tax code', function (): void {
        $id = (string) createTaxCode(['code' => 'LOCKED'])->getKey();

        foreach (['deactivate', 'reactivate'] as $action) {
            expect(asTaxCodeApi($this->bookkeeper, 'POST', taxCodeUri("{$id}/{$action}"))->getStatusCode())->toBe(403);
        }

        expect(asTaxCodeApi($this->bookkeeper, 'PUT', taxCodeUri($id), ['name' => 'Changed'])->getStatusCode())->toBe(403)
            ->and(asTaxCodeApi($this->bookkeeper, 'POST', taxCodeUri("{$id}/end-range"), ['last_effective_day' => '2026-06-30'])->getStatusCode())->toBe(403)
            ->and(asTaxCodeApi($this->bookkeeper, 'DELETE', taxCodeUri($id))->getStatusCode())->toBe(403);
    });

    it('lets an accountant restore a deleted tax code but refuses a bookkeeper', function (): void {
        $forBookkeeper = createTaxCode(['code' => 'BOOK-RESTORE']);
        app(TaxCodeService::class)->delete($forBookkeeper);

        $forAccountant = createTaxCode(['code' => 'ACCT-RESTORE']);
        app(TaxCodeService::class)->delete($forAccountant);

        expect(asTaxCodeApi($this->bookkeeper, 'POST', taxCodeUri($forBookkeeper->getKey().'/restore'))->getStatusCode())->toBe(403)
            ->and(asTaxCodeApi($this->accountant, 'POST', taxCodeUri($forAccountant->getKey().'/restore'))->getStatusCode())->toBe(200);
    });
});

describe('isolation', function (): void {
    it('never returns another workspace’s tax codes from the index', function (): void {
        $ownTaxCode = createTaxCode(['code' => 'ACME-TAX']);

        $foreignTaxCode = app(TenantContext::class)->runFor(
            $this->globex['tenant'],
            fn () => app(TaxCodeService::class)->create($this->globex['company'], new TaxCodeData(
                code: 'GLOBEX-TAX',
                name: 'Globex Tax',
                taxType: TaxType::ZeroRated,
                rate: '0',
                effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            )),
        );

        $response = asTaxCodeApi($this->accountant, 'GET', taxCodeUri());

        // Gated on toBeEnvelope() and on containing the caller's own code first: without both, "does
        // not contain the foreign id" would pass just as well against an empty 404 body, which proves
        // nothing about isolation — every unmatched url in the application 404s that way.
        expect($response)->toBeEnvelope();

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toContain((string) $ownTaxCode->getKey())
            ->and($ids)->not->toContain((string) $foreignTaxCode->getKey());
    });

    it('hides another workspace’s tax code from a direct show', function (): void {
        $ownTaxCode = createTaxCode(['code' => 'ACME-TAX']);

        $foreignTaxCode = app(TenantContext::class)->runFor(
            $this->globex['tenant'],
            fn () => app(TaxCodeService::class)->create($this->globex['company'], new TaxCodeData(
                code: 'GLOBEX-TAX',
                name: 'Globex Tax',
                taxType: TaxType::ZeroRated,
                rate: '0',
                effectiveFrom: CarbonImmutable::parse('2026-01-01'),
            )),
        );

        // Proven against a tax code that genuinely is reachable first: a bare 404 for an unmatched
        // url would otherwise satisfy the assertion below for the wrong reason, since every undefined
        // route in the application 404s with exactly this same generic shape.
        expect(asTaxCodeApi($this->accountant, 'GET', taxCodeUri((string) $ownTaxCode->getKey()))->getStatusCode())
            ->toBe(200);

        $response = asTaxCodeApi($this->accountant, 'GET', taxCodeUri((string) $foreignTaxCode->getKey()));

        expect($response)->toBeProblem('not-found', 404);
    });

    it('refuses a caller with no membership of the company named in the url', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        $response = test()->actingAs(RowLevelSecurity::bypass(fn () => $this->accountant->fresh()))
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $second->getKey())
            ->getJson('/api/v1/companies/'.$second->getKey().'/tax-codes');

        expect($response)->toBeProblem('company-not-available', 404);
    });

    it('refuses a member of a sibling company reaching this company’s tax code', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $foreignTaxCode = app(TaxCodeService::class)->create($second, new TaxCodeData(
            code: 'SIBLING',
            name: 'Sibling Tax',
            taxType: TaxType::ZeroRated,
            rate: '0',
            effectiveFrom: CarbonImmutable::parse('2026-01-01'),
        ));

        $response = asTaxCodeApi($this->accountant, 'GET', taxCodeUri((string) $foreignTaxCode->getKey()));

        expect($response)->toBeProblem('forbidden', 403);
    });
});

describe('the index', function (): void {
    it('lists tax codes for the company with a total in meta', function (): void {
        $taxCode = createTaxCode(['code' => 'LISTED']);

        $response = asTaxCodeApi($this->accountant, 'GET', taxCodeUri());

        expect($response)->toBeEnvelope()
            ->and($response->json('meta.total'))->toBeGreaterThanOrEqual(1)
            ->and(collect($response->json('data'))->pluck('id')->all())->toContain((string) $taxCode->getKey());
    });

    it('excludes inactive codes by default but includes them when asked', function (): void {
        $inactive = createTaxCode(['code' => 'HIDDEN']);
        app(TaxCodeService::class)->deactivate($inactive);

        $default = asTaxCodeApi($this->accountant, 'GET', taxCodeUri());
        expect(collect($default->json('data'))->pluck('id')->all())->not->toContain((string) $inactive->getKey());

        $all = asTaxCodeApi($this->accountant, 'GET', taxCodeUri().'?active_only=0');
        // Anchored on positive containment: this is what actually requires the endpoint to exist and
        // work, since an empty 404 body would trivially satisfy the not->toContain() line above.
        expect(collect($all->json('data'))->pluck('id')->all())->toContain((string) $inactive->getKey());
    });

    it('filters by code', function (): void {
        createTaxCode(['code' => 'FINDME']);
        createTaxCode(['code' => 'IGNOREME']);

        $response = asTaxCodeApi($this->accountant, 'GET', taxCodeUri().'?code=findme');

        $codes = collect($response->json('data'))->pluck('code')->all();

        expect($codes)->toContain('FINDME')->not->toContain('IGNOREME');
    });

    it('ignores an array code filter rather than crashing', function (): void {
        // Security review S2: `?code[]=x` passed `$request->filled('code')` and then hit
        // `$request->string('code')`, which array-to-string-converts an array into an E_WARNING that
        // the framework's error handler escalates to an ErrorException, rendered as a 500. The code
        // filter is only applied when the raw query value is actually a string.
        createTaxCode(['code' => 'ARRAYSAFE']);

        $response = asTaxCodeApi($this->accountant, 'GET', taxCodeUri().'?code[]=x');

        expect($response)->toBeEnvelope();
    });
});

describe('creating a tax code', function (): void {
    it('creates a zero-rated tax code with no output account', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'ZERO', 'name' => 'Zero Rated Supply', 'tax_type' => 'zero_rated',
            'rate' => '0', 'effective_from' => '2026-01-01',
        ]);

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.rate'))->toBe('0.0000')
            ->and($response->json('data.output_account_id'))->toBeNull()
            ->and($response->json('data.capabilities.charges_tax'))->toBeFalse();
    });

    it('creates a charging tax code posting to a liability account', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'VAT', 'name' => 'Value Added Tax', 'tax_type' => 'vat', 'rate' => '18',
            'effective_from' => '2026-01-01', 'output_account_id' => (string) $this->outputVat->getKey(),
        ]);

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.rate'))->toBe('18.0000')
            ->and($response->json('data.output_account_id'))->toBe((string) $this->outputVat->getKey())
            ->and($response->json('data.capabilities.charges_tax'))->toBeTrue();
    });

    it('refuses a blank code', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => '   ', 'name' => 'Zero Rated Supply', 'tax_type' => 'zero_rated',
            'rate' => '0', 'effective_from' => '2026-01-01',
        ]);

        expect($response)->toBeProblem('tax-code-blank', 422);
    });

    it('refuses an invented tax type', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'X', 'name' => 'Nonsense', 'tax_type' => 'nonsense', 'rate' => '0', 'effective_from' => '2026-01-01',
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('rejects a non-numeric rate before it reaches the service', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'X', 'name' => 'Bad Rate', 'tax_type' => 'zero_rated', 'rate' => 'abc', 'effective_from' => '2026-01-01',
        ]);

        // As with a customer's credit_limit: §5.4 lists `tax-rate-not-a-number` as a code this
        // endpoint can produce, but the request rule's regex (`^-?\d{1,3}(\.\d{1,4})?$`) rejects any
        // non-numeric shape first, so the service's own check is unreachable through HTTP as
        // specified — flagged in the report back to the Delivery Manager.
        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses a negative rate and names the actual problem', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'NEG', 'name' => 'Negative', 'tax_type' => 'vat', 'rate' => '-5',
            'effective_from' => '2026-01-01', 'output_account_id' => (string) $this->outputVat->getKey(),
        ]);

        // The regex deliberately lets a leading minus through (design §5.2), the same device used
        // for a customer's credit_limit, so the service names this rather than a generic failure.
        expect($response)->toBeProblem('negative-tax-rate', 422);
    });

    it('refuses a rate above one hundred', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'HIGH', 'name' => 'Too High', 'tax_type' => 'vat', 'rate' => '150',
            'effective_from' => '2026-01-01', 'output_account_id' => (string) $this->outputVat->getKey(),
        ]);

        expect($response)->toBeProblem('tax-rate-above-one-hundred', 422);
    });

    it('refuses a non-zero rate on an exempt code', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'EXEMPT', 'name' => 'Exempt', 'tax_type' => 'exempt', 'rate' => '5', 'effective_from' => '2026-01-01',
        ]);

        expect($response)->toBeProblem('zero-rate-type-with-rate', 422);
    });

    it('refuses an inverted effective range', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'INVERTED', 'name' => 'Inverted', 'tax_type' => 'zero_rated', 'rate' => '0',
            'effective_from' => '2026-06-01', 'effective_to' => '2026-01-01',
        ]);

        expect($response)->toBeProblem('effective-range-inverted', 422);
    });

    it('refuses a charging rate with no output account', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'NOOUTPUT', 'name' => 'No Output', 'tax_type' => 'vat', 'rate' => '18',
            'effective_from' => '2026-01-01',
        ]);

        expect($response)->toBeProblem('output-account-required', 422);
    });

    it('refuses an output account that is not a liability', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'WRONGOUT', 'name' => 'Wrong Type', 'tax_type' => 'vat', 'rate' => '18',
            'effective_from' => '2026-01-01', 'output_account_id' => (string) $this->inputVat->getKey(),
        ]);

        expect($response)->toBeProblem('output-account-wrong-type', 422);
    });

    it('refuses an output account that does not accept postings', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'HEADINGOUT', 'name' => 'Heading Output', 'tax_type' => 'vat', 'rate' => '18',
            'effective_from' => '2026-01-01', 'output_account_id' => (string) $this->liabilityHeading->getKey(),
        ]);

        expect($response)->toBeProblem('account-not-postable', 422);
    });

    it('refuses an input account that is not an asset', function (): void {
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'WRONGIN', 'name' => 'Wrong Input', 'tax_type' => 'zero_rated', 'rate' => '0',
            'effective_from' => '2026-01-01', 'input_account_id' => (string) $this->outputVat->getKey(),
        ]);

        expect($response)->toBeProblem('input-account-wrong-type', 422);
    });

    it('refuses an account belonging to another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $foreignAccount = Account::query()->forCompany($second->getKey())->where('code', '2140')->firstOrFail();

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'FOREIGNACC', 'name' => 'Foreign Account', 'tax_type' => 'vat', 'rate' => '18',
            'effective_from' => '2026-01-01', 'output_account_id' => (string) $foreignAccount->getKey(),
        ]);

        expect($response)->toBeProblem('account-outside-company', 422);
    });

    it('refuses overlapping ranges for the same code', function (): void {
        app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'OVERLAP'));

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'OVERLAP', 'name' => 'Value Added Tax', 'tax_type' => 'vat', 'rate' => '20',
            'effective_from' => '2026-06-01', 'output_account_id' => (string) $this->outputVat->getKey(),
        ]);

        expect($response)->toBeProblem('tax-code-range-overlaps', 409);
    });

    it('honours an explicit is_active: false rather than ignoring it', function (): void {
        // The contrast with a customer's `status`: `is_active` is a documented, settable field on
        // this request (design §5.2), not a state that only moves through a named action endpoint.
        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'PRECREATED', 'name' => 'Pre-created inactive', 'tax_type' => 'zero_rated',
            'rate' => '0', 'effective_from' => '2026-01-01', 'is_active' => false,
        ]);

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.is_active'))->toBeFalse();
    });
});

describe('showing and updating a tax code', function (): void {
    it('shows a tax code', function (): void {
        $taxCode = createTaxCode(['code' => 'SHOWME']);

        $response = asTaxCodeApi($this->accountant, 'GET', taxCodeUri((string) $taxCode->getKey()));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.id'))->toBe((string) $taxCode->getKey())
            ->and($response->json('data.code'))->toBe('SHOWME');
    });

    it('updates a tax code’s name', function (): void {
        $taxCode = createTaxCode(['code' => 'RENAME']);

        $response = asTaxCodeApi($this->accountant, 'PUT', taxCodeUri((string) $taxCode->getKey()), ['name' => 'Renamed Code']);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.name'))->toBe('Renamed Code');
    });

    it('reopens a closed range when the put sets effective_to to null', function (): void {
        $taxCode = app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'REOPEN', to: '2026-06-30'));

        $response = asTaxCodeApi($this->accountant, 'PUT', taxCodeUri((string) $taxCode->getKey()), ['effective_to' => null]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.effective_to'))->toBeNull()
            ->and($response->json('data.is_open_ended'))->toBeTrue();
    });

    it('leaves a closed effective_to untouched when the put omits it', function (): void {
        $taxCode = app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'KEEPCLOSED', to: '2026-06-30'));

        $response = asTaxCodeApi($this->accountant, 'PUT', taxCodeUri((string) $taxCode->getKey()), ['name' => 'Renamed Only']);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.effective_to'))->toBe('2026-06-30')
            ->and($response->json('data.is_open_ended'))->toBeFalse();
    });

    it('refuses a rate change once the rate has been applied to a document', function (): void {
        $taxCode = app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'APPLIED'));
        withTaxRateApplied(true);

        $response = asTaxCodeApi($this->accountant, 'PUT', taxCodeUri((string) $taxCode->getKey()), ['rate' => '20']);

        expect($response)->toBeProblem('tax-rate-already-applied', 409);
    });

    it('refuses an effective_from change once the rate has been applied to a document', function (): void {
        $taxCode = app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'APPLIED2'));
        withTaxRateApplied(true);

        $response = asTaxCodeApi($this->accountant, 'PUT', taxCodeUri((string) $taxCode->getKey()), ['effective_from' => '2026-02-01']);

        expect($response)->toBeProblem('tax-rate-start-already-applied', 409);
    });
});

describe('the lifecycle actions', function (): void {
    it('ends a range and lets a successor take over the following day', function (): void {
        $taxCode = app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'VAT', rate: '18'));

        $endResponse = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($taxCode->getKey().'/end-range'), [
            'last_effective_day' => '2026-06-30',
        ]);

        expect($endResponse)->toBeEnvelope()
            ->and($endResponse->json('data.effective_to'))->toBe('2026-06-30')
            ->and($endResponse->json('data.is_open_ended'))->toBeFalse();

        $successorResponse = asTaxCodeApi($this->accountant, 'POST', taxCodeUri(), [
            'code' => 'VAT', 'name' => 'Value Added Tax', 'tax_type' => 'vat', 'rate' => '20',
            'effective_from' => '2026-07-01', 'output_account_id' => (string) $this->outputVat->getKey(),
        ]);

        expect($successorResponse)->toBeEnvelope(201)
            ->and($successorResponse->json('data.rate'))->toBe('20.0000');
    });

    it('refuses to end a range before it starts', function (): void {
        $taxCode = app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'TOOSOON'));

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($taxCode->getKey().'/end-range'), [
            'last_effective_day' => '2025-12-31',
        ]);

        expect($response)->toBeProblem('range-ends-before-it-starts', 422);
    });

    it('deactivates a tax code', function (): void {
        $taxCode = createTaxCode(['code' => 'DEACTME']);

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($taxCode->getKey().'/deactivate'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.is_active'))->toBeFalse();
    });

    it('refuses to deactivate an already-inactive tax code', function (): void {
        $taxCode = createTaxCode(['code' => 'ALREADYOFF']);
        app(TaxCodeService::class)->deactivate($taxCode);

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($taxCode->getKey().'/deactivate'));

        expect($response)->toBeProblem('tax-code-already-inactive', 422);
    });

    it('reactivates a tax code', function (): void {
        $taxCode = createTaxCode(['code' => 'REACT']);
        app(TaxCodeService::class)->deactivate($taxCode);

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($taxCode->getKey().'/reactivate'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.is_active'))->toBeTrue();
    });

    it('refuses to reactivate an already-active tax code', function (): void {
        $taxCode = createTaxCode(['code' => 'ALREADYON']);

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($taxCode->getKey().'/reactivate'));

        expect($response)->toBeProblem('tax-code-already-active', 422);
    });

    it('soft-deletes a tax code, then restores it', function (): void {
        $id = (string) createTaxCode(['code' => 'ROUNDTRIP'])->getKey();

        expect(asTaxCodeApi($this->accountant, 'DELETE', taxCodeUri($id))->getStatusCode())->toBe(204);

        expect(asTaxCodeApi($this->accountant, 'GET', taxCodeUri($id))->getStatusCode())->toBe(404);

        $restoreResponse = asTaxCodeApi($this->accountant, 'POST', taxCodeUri("{$id}/restore"));
        expect($restoreResponse)->toBeEnvelope()
            ->and($restoreResponse->json('data.id'))->toBe($id);

        expect(asTaxCodeApi($this->accountant, 'GET', taxCodeUri($id))->getStatusCode())->toBe(200);
    });

    it('refuses to delete a tax code that has been applied to a document', function (): void {
        $taxCode = createTaxCode(['code' => 'INUSE']);
        withTaxRateApplied(true);

        $response = asTaxCodeApi($this->accountant, 'DELETE', taxCodeUri((string) $taxCode->getKey()));

        expect($response)->toBeProblem('tax-code-in-use', 422);
    });

    it('refuses to restore a tax code whose range has since been taken by another', function (): void {
        $original = app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'CLASH', to: '2026-06-30'));
        app(TaxCodeService::class)->delete($original);

        app(TaxCodeService::class)->create($this->company, chargingTaxCodeData(code: 'CLASH', to: '2026-06-30'));

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($original->getKey().'/restore'));

        expect($response)->toBeProblem('tax-code-range-taken-on-restore', 409);
    });

    it('refuses to restore a tax code that was never deleted', function (): void {
        $taxCode = createTaxCode(['code' => 'NOTDELETED']);

        $response = asTaxCodeApi($this->accountant, 'POST', taxCodeUri($taxCode->getKey().'/restore'));

        expect($response)->toBeProblem('tax-code-not-deleted', 422);
    });
});
