<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\DTOs\JournalLineData;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Authorization\Application\DTOs\RoleData;
use Asids\Core\Authorization\Application\Services\RoleService;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceLineData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * The receivables reports over HTTP — Milestone 8A.
 *
 * What this file is *for* is the transport layer: authorisation, the shape of the envelope, the serialisation
 * of `Money` into decimal strings, and the one query parameter. The figures themselves already have 59 tests
 * across `OutstandingBalanceReportTest`, `AgedReceivablesReportTest` and `ArControlReconciliationTest`, which
 * own the bucket edges, the collectable-status filter, the ordering and the line-number-1 invariant.
 * Re-asserting those here would put two copies of one rule in the suite, so this file asserts only what the
 * controller is responsible for — plus the handful of end-to-end facts that prove the controller really is
 * calling the service rather than reimplementing it.
 *
 * HOW THE AGEING TESTS AVOID DATING INVOICES INTO THE PAST
 * -------------------------------------------------------
 * An invoice cannot be due before it is issued (`assertDates()`), and a posting must land in an open fiscal
 * period, so producing a ninety-day-overdue invoice by back-dating one means opening whichever fiscal year
 * that date happens to fall in. Instead every invoice below is dated today and due today, and the *cutoff*
 * moves: asking for the book as at today plus forty-five days makes a due-today invoice forty-five days
 * overdue. That keeps the fixtures inside one open period and has the useful side effect of proving the
 * supplied `as_of` genuinely reaches the service, since nothing else could move the figures between buckets.
 *
 * A trap this file is deliberately built against: every unmatched url in this application 404s with the same
 * generic `not-found` body. An isolation assertion phrased only as "the response is a 404" would therefore
 * pass against a typo in the route, before any real isolation existed. Each such assertion below is gated
 * behind a positive request that only a working endpoint can satisfy.
 *
 * Helper names are prefixed `rpt`/`asReportApi`/`reportUri` because Pest loads every matched file into one
 * process and top-level function names are global — two files declaring the same one is a fatal error for the
 * whole suite.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::now());

    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();
    $this->tradeReceivables = Account::query()->forCompany($this->company->getKey())
        ->withSystemKey(Account::TRADE_RECEIVABLES)->firstOrFail();
    $this->otherReceivables = Account::query()->forCompany($this->company->getKey())
        ->where('code', '1140')->firstOrFail();

    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'rpt-acct@acme.test']);
    $this->bookkeeper = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', ['email' => 'rpt-book@acme.test']);
    $this->viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer', ['email' => 'rpt-view@acme.test']);

    // Permission and membership are different questions, and the endpoints ask both.
    $memberships = app(MembershipService::class);
    foreach ([$this->accountant, $this->bookkeeper, $this->viewer] as $member) {
        $memberships->grant($this->company, $member, $this->owner);
    }
});

/**
 * A member of the acme company, calling a receivables report.
 *
 * @param  array<string, mixed>  $query
 */
function asReportApi(User $user, string $uri, array $query = []): TestResponse
{
    $fresh = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($fresh ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->withHeader('X-Company', test()->company->getKey())
        ->getJson($query === [] ? $uri : $uri.'?'.http_build_query($query));
}

function reportUri(string $report, ?Company $company = null): string
{
    return '/api/v1/companies/'.($company ?? test()->company)->getKey().'/reports/'.$report;
}

/**
 * A customer, optionally pointed at its own receivable account so a second AR row appears.
 */
function rptCustomer(string $code, ?Account $receivable = null, ?Company $company = null): Customer
{
    return app(CustomerService::class)->create(
        $company ?? test()->company,
        new CustomerData(
            name: $code.' Ltd',
            code: $code,
            receivableAccountId: $receivable === null ? null : (string) $receivable->getKey(),
        ),
    );
}

/**
 * An issued invoice dated and due today, so its posting lands in the open period and the cutoff — not the
 * invoice date — is what decides its bucket.
 */
function rptInvoice(Customer $customer, string $unitPrice, ?Company $company = null, ?Account $revenue = null): SalesInvoice
{
    $company ??= test()->company;
    $today = CarbonImmutable::now()->startOfDay();

    $draft = app(SalesInvoiceService::class)->createDraft($company, new SalesInvoiceData(
        customerId: (string) $customer->getKey(),
        invoiceDate: $today,
        dueDate: $today,
        lines: [new SalesInvoiceLineData(
            description: 'Consulting services',
            quantity: '1',
            unitPrice: $unitPrice,
            revenueAccountId: (string) ($revenue ?? test()->revenue)->getKey(),
        )],
    ));

    return app(SalesInvoiceService::class)->issue($draft, test()->owner);
}

/**
 * A posted journal that never went through invoicing — the thing an AR reconciliation exists to catch.
 */
function rptManualJournal(Account $debit, Account $credit, string $amount): void
{
    app(PostingService::class)->postNew(test()->company, new JournalEntryData(
        entryDate: CarbonImmutable::now()->startOfDay(),
        description: 'Manual journal posted outside invoicing',
        lines: [
            new JournalLineData(accountId: (string) $debit->getKey(), debit: Money::of($amount, 'LKR')),
            new JournalLineData(accountId: (string) $credit->getKey(), credit: Money::of($amount, 'LKR')),
        ],
    ), test()->owner);
}

/**
 * A role holding everything the reports need *except* the reports capability.
 *
 * Built rather than reused because every built-in template that can reach these endpoints legitimately holds
 * `sales.reports.view` — the administrator template is defined as every tenant-grantable capability, so it
 * gained the new permission the moment it entered the catalogue. Only a purpose-built role isolates the
 * permission from the role. `assign()` replaces the user's whole role set, so the bookkeeper grant this user
 * was created with does not survive it.
 */
function rptUserWithoutReportsPermission(): User
{
    $roles = app(RoleService::class);

    $role = $roles->create(new RoleData(
        label: 'Sales Clerk',
        description: 'Maintains customers but reads no receivables reporting.',
        permissionNames: [
            'organization.companies.view',
            'sales.customers.view',
            'sales.invoices.view',
        ],
    ), test()->owner);

    $user = test()->createUserWithRole(test()->acme['tenant'], 'bookkeeper', ['email' => 'rpt-clerk@acme.test']);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);
    $roles->assign($user, [$role->getKey()], test()->owner);

    return $user;
}

/** The three reports, for the authorisation matrix. */
dataset('receivableReports', ['outstanding-receivables', 'aged-receivables', 'ar-control']);

describe('authorization', function (): void {
    it('serves an accountant the standard envelope', function (string $report): void {
        expect(asReportApi($this->accountant, reportUri($report)))->toBeEnvelope();
    })->with('receivableReports');

    it('serves a bookkeeper and a viewer, per the approved role grants', function (string $report): void {
        expect(asReportApi($this->bookkeeper, reportUri($report)))->toBeEnvelope()
            ->and(asReportApi($this->viewer, reportUri($report)))->toBeEnvelope();
    })->with('receivableReports');

    it('refuses a caller whose role omits sales.reports.view', function (string $report): void {
        $clerk = rptUserWithoutReportsPermission();

        // Gated on the endpoint genuinely working for someone first, so a 403 from a broken route cannot
        // pass for a 403 from the permission check.
        expect(asReportApi($this->accountant, reportUri($report)))->toBeEnvelope();

        expect(asReportApi($clerk, reportUri($report)))->toBeProblem('forbidden', 403);
    })->with('receivableReports');

    it('refuses a caller with no membership of the company named in the url', function (string $report): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        // Deliberately not asReportApi(): that helper always addresses test()->company, and this test needs
        // to name a company in the same workspace that the accountant is not a member of.
        $fresh = RowLevelSecurity::bypass(fn (): ?User => $this->accountant->fresh());

        $response = test()->actingAs($fresh ?? $this->accountant)
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $second->getKey())
            ->getJson(reportUri($report, $second));

        expect($response)->toBeProblem('company-not-available', 404);
    })->with('receivableReports');

    it('hides another workspace’s company entirely', function (string $report): void {
        $foreign = $this->globex['company'];

        expect(asReportApi($this->accountant, reportUri($report)))->toBeEnvelope();

        // Row level security makes the foreign company invisible to acme's tenant context, so binding fails
        // before any policy runs — a 404 that reveals nothing about whether the id exists.
        expect(asReportApi($this->accountant, reportUri($report, $foreign)))->toBeProblem('not-found', 404);
    })->with('receivableReports');

    it('refuses an unauthenticated caller', function (string $report): void {
        expect(test()->withHeader('X-Tenant', 'acme')->getJson(reportUri($report)))
            ->toBeProblem('unauthenticated', 401);
    })->with('receivableReports');
});

describe('outstanding receivables', function (): void {
    it('returns a row per customer with its invoice count and a decimal-string amount', function (): void {
        $silva = rptCustomer('SILVA');
        rptInvoice($silva, '1000.00');
        rptInvoice($silva, '250.50');

        $response = asReportApi($this->accountant, reportUri('outstanding-receivables'));

        expect($response)->toBeEnvelope();

        $row = collect($response->json('data'))->firstWhere('code', 'SILVA');

        expect($row['name'])->toBe('SILVA Ltd')
            ->and($row['invoice_count'])->toBe(2)
            // Four decimal places, and a string. A JSON number here would be an IEEE-754 double by the time
            // any client read it, which is the whole reason the ledger stores numeric(19,4).
            ->and($row['outstanding'])->toBe('1250.5000')
            ->and($row['outstanding'])->toBeString();
    });

    it('reports a server-computed total equal to the sum of its rows', function (): void {
        rptInvoice(rptCustomer('AAA'), '400.00');
        rptInvoice(rptCustomer('BBB'), '600.00');

        $response = asReportApi($this->accountant, reportUri('outstanding-receivables'));

        expect($response->json('meta.totals.outstanding'))->toBe('1000.0000')
            ->and($response->json('meta.currency'))->toBe($this->company->base_currency_code)
            ->and($response->json('meta.as_of'))->toBe(CarbonImmutable::now()->toDateString());
    });

    it('returns an empty report and a zero total when nothing is owed', function (): void {
        // A customer with no invoices at all. The report excludes zero balances, so "nobody owes anything"
        // is an empty list rather than a list of zeroes.
        rptCustomer('QUIET');

        $response = asReportApi($this->accountant, reportUri('outstanding-receivables'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->toBe([])
            ->and($response->json('meta.totals.outstanding'))->toBe('0.0000');
    });

    it('excludes a cancelled invoice, which still carries its full amount_due', function (): void {
        $silva = rptCustomer('SILVA');
        $keep = rptInvoice($silva, '1000.00');
        $cancel = rptInvoice($silva, '9999.00');

        app(SalesInvoiceService::class)->cancel($cancel, 'Raised against the wrong customer.', $this->owner);

        // Cancellation does not zero `amount_due` — a CHECK holds it at total minus amount_paid — so only the
        // status filter keeps it out. A controller that summed the column itself would report 10999.
        expect(asReportApi($this->accountant, reportUri('outstanding-receivables'))->json('meta.totals.outstanding'))
            ->toBe('1000.0000')
            ->and($keep->refresh()->status->value)->toBe('issued');
    });

    it('preserves the service’s ordering, largest debt first', function (): void {
        rptInvoice(rptCustomer('SMALL'), '100.00');
        rptInvoice(rptCustomer('LARGE'), '5000.00');
        rptInvoice(rptCustomer('MIDDLE'), '900.00');

        $codes = collect(asReportApi($this->accountant, reportUri('outstanding-receivables'))->json('data'))
            ->pluck('code')->all();

        expect($codes)->toBe(['LARGE', 'MIDDLE', 'SMALL']);
    });
});

describe('aged receivables', function (): void {
    it('ages against a supplied cutoff', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        // Due today, asked as at today plus forty-five days: forty-five days overdue. Nothing but the cutoff
        // reaching the service could move it out of days_0_30.
        $response = asReportApi($this->accountant, reportUri('aged-receivables'), [
            'as_of' => CarbonImmutable::now()->addDays(45)->toDateString(),
        ]);

        $row = collect($response->json('data'))->firstWhere('code', 'SILVA');

        expect($row['days_31_60'])->toBe('1000.0000')
            ->and($row['days_0_30'])->toBe('0.0000')
            ->and($row['not_yet_due'])->toBe('0.0000')
            ->and($row['total'])->toBe('1000.0000');
    });

    it('puts an invoice due exactly on the cutoff in the current bucket', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        $row = collect(asReportApi($this->accountant, reportUri('aged-receivables'), [
            'as_of' => CarbonImmutable::now()->toDateString(),
        ])->json('data'))->firstWhere('code', 'SILVA');

        // Zero days overdue, not "not yet due" and not overdue by one.
        expect($row['days_0_30'])->toBe('1000.0000')
            ->and($row['not_yet_due'])->toBe('0.0000');
    });

    it('treats an invoice due after the cutoff as not yet due rather than excluding it', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        $row = collect(asReportApi($this->accountant, reportUri('aged-receivables'), [
            'as_of' => CarbonImmutable::now()->subDays(10)->toDateString(),
        ])->json('data'))->firstWhere('code', 'SILVA');

        expect($row['not_yet_due'])->toBe('1000.0000')
            ->and($row['total'])->toBe('1000.0000');
    });

    it('reaches the oldest bucket', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        $row = collect(asReportApi($this->accountant, reportUri('aged-receivables'), [
            'as_of' => CarbonImmutable::now()->addDays(120)->toDateString(),
        ])->json('data'))->firstWhere('code', 'SILVA');

        expect($row['days_over_90'])->toBe('1000.0000');
    });

    it('defaults the cutoff server-side and reports the date it used', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        $response = asReportApi($this->accountant, reportUri('aged-receivables'));

        // The client's clock never decides the cutoff. The server picks today and says so, which is what
        // lets the page put the resolved date in its own control.
        expect($response)->toBeEnvelope()
            ->and($response->json('meta.as_of'))->toBe(CarbonImmutable::now()->toDateString());
    });

    it('echoes a supplied cutoff back unchanged', function (): void {
        $asOf = CarbonImmutable::now()->addDays(45)->toDateString();

        expect(asReportApi($this->accountant, reportUri('aged-receivables'), ['as_of' => $asOf])->json('meta.as_of'))
            ->toBe($asOf);
    });

    it('refuses a cutoff that is not a date', function (): void {
        expect(asReportApi($this->accountant, reportUri('aged-receivables'), ['as_of' => 'the-end-of-june']))
            ->toBeProblem('validation-failed', 422);
    });

    it('carries every bucket key even for a customer whose debt sits in one', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        $row = collect(asReportApi($this->accountant, reportUri('aged-receivables'))->json('data'))
            ->firstWhere('code', 'SILVA');

        expect($row)->toHaveKeys([
            'customer_id', 'code', 'name',
            'not_yet_due', 'days_0_30', 'days_31_60', 'days_61_90', 'days_over_90', 'total',
        ]);
    });

    it('reports column totals that equal the sum of the rows', function (): void {
        rptInvoice(rptCustomer('AAA'), '400.00');
        rptInvoice(rptCustomer('BBB'), '600.00');

        $response = asReportApi($this->accountant, reportUri('aged-receivables'));

        expect($response->json('meta.totals.days_0_30'))->toBe('1000.0000')
            ->and($response->json('meta.totals.total'))->toBe('1000.0000')
            ->and($response->json('meta.totals.days_over_90'))->toBe('0.0000');
    });

    it('agrees with the outstanding balance report for the same company', function (): void {
        rptInvoice(rptCustomer('AAA'), '400.00');
        rptInvoice(rptCustomer('BBB'), '600.50');

        // Two reports, one truth. Both read `amount_due` over collectable invoices, and a total that differed
        // between them would mean one of the two endpoints had started doing its own arithmetic.
        $aged = asReportApi($this->accountant, reportUri('aged-receivables'))->json('meta.totals.total');
        $outstanding = asReportApi($this->accountant, reportUri('outstanding-receivables'))->json('meta.totals.outstanding');

        expect($aged)->toBe($outstanding)->toBe('1000.5000');
    });

    it('returns an empty report when nothing is outstanding', function (): void {
        rptCustomer('QUIET');

        $response = asReportApi($this->accountant, reportUri('aged-receivables'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->toBe([])
            ->and($response->json('meta.totals.total'))->toBe('0.0000');
    });
});

describe('AR control reconciliation', function (): void {
    it('reconciles a company whose receivables came only from invoices', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        $response = asReportApi($this->accountant, reportUri('ar-control'));

        $row = collect($response->json('data'))->firstWhere('code', $this->tradeReceivables->code);

        expect($row['subledger'])->toBe('1000.0000')
            ->and($row['general_ledger'])->toBe('1000.0000')
            ->and($row['difference'])->toBe('0.0000')
            ->and($row['reconciles'])->toBeTrue()
            ->and($response->json('meta.totals.reconciles'))->toBeTrue()
            ->and($response->json('meta.totals.difference'))->toBe('0.0000');
    });

    it('surfaces a positive difference when a manual journal debits AR', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');
        rptManualJournal($this->tradeReceivables, $this->otherReceivables, '250.00');

        $response = asReportApi($this->accountant, reportUri('ar-control'));

        $row = collect($response->json('data'))->firstWhere('code', $this->tradeReceivables->code);

        // Ledger minus subledger: the books carry more receivable than the invoices account for, which is the
        // direction a stray journal into AR shows up in.
        expect($row['general_ledger'])->toBe('1250.0000')
            ->and($row['subledger'])->toBe('1000.0000')
            ->and($row['difference'])->toBe('250.0000')
            ->and($row['reconciles'])->toBeFalse()
            ->and($response->json('meta.totals.reconciles'))->toBeFalse();
    });

    it('keeps a negative difference negative', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');
        rptManualJournal($this->otherReceivables, $this->tradeReceivables, '250.00');

        $response = asReportApi($this->accountant, reportUri('ar-control'));

        $row = collect($response->json('data'))->firstWhere('code', $this->tradeReceivables->code);

        // The sign says which side is short. Normalising it — or blanking it — would discard the only clue
        // about what went wrong, which is why the serialisation is asserted rather than assumed.
        expect($row['difference'])->toBe('-250.0000')
            ->and($row['general_ledger'])->toBe('750.0000')
            ->and($row['reconciles'])->toBeFalse()
            ->and($response->json('meta.totals.difference'))->toBe('-250.0000')
            ->and($response->json('meta.totals.reconciles'))->toBeFalse();
    });

    it('refuses to call two opposing errors reconciled just because they net to zero', function (): void {
        // A second AR account enters the report by a customer pointing at it and being invoiced.
        rptInvoice(rptCustomer('SILVA'), '1000.00');
        rptInvoice(rptCustomer('OTHER', $this->otherReceivables), '1000.00');

        // One account too high, the other too low, by the same amount.
        rptManualJournal($this->tradeReceivables, $this->otherReceivables, '250.00');

        $response = asReportApi($this->accountant, reportUri('ar-control'));

        $rows = collect($response->json('data'))->keyBy('code');

        expect($rows[$this->tradeReceivables->code]['difference'])->toBe('250.0000')
            ->and($rows[$this->otherReceivables->code]['difference'])->toBe('-250.0000')
            // The grand total nets to nothing, and the verdict is still false — which is the whole reason the
            // verdict is emitted rather than left for a client to infer from the total.
            ->and($response->json('meta.totals.difference'))->toBe('0.0000')
            ->and($response->json('meta.totals.reconciles'))->toBeFalse();
    });

    it('reports the day it was produced and accepts no cutoff', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        // A cutoff is meaningless here: the subledger side reads current status and current amounts, so a past
        // date would have the two halves answering different questions. An as_of in the query string must
        // change nothing.
        $today = CarbonImmutable::now()->toDateString();

        $plain = asReportApi($this->accountant, reportUri('ar-control'));
        $withDate = asReportApi($this->accountant, reportUri('ar-control'), [
            'as_of' => CarbonImmutable::now()->subYear()->toDateString(),
        ]);

        expect($plain->json('meta.as_of'))->toBe($today)
            ->and($withDate->json('meta.as_of'))->toBe($today)
            ->and($withDate->json('data'))->toBe($plain->json('data'));
    });

    it('names the account on every row', function (): void {
        rptInvoice(rptCustomer('SILVA'), '1000.00');

        $row = collect(asReportApi($this->accountant, reportUri('ar-control'))->json('data'))
            ->firstWhere('code', $this->tradeReceivables->code);

        expect($row)->toHaveKeys([
            'account_id', 'code', 'name',
            'subledger', 'general_ledger', 'difference', 'reconciles',
        ])->and($row['account_id'])->toBe((string) $this->tradeReceivables->getKey())
            ->and($row['name'])->toBe($this->tradeReceivables->name);
    });

    it('still lists the system receivable account for a company with no invoices', function (): void {
        // Zeroes rather than an empty list: the account exists and holds nothing, and a report that omitted it
        // would be indistinguishable from one that could not find it.
        $response = asReportApi($this->accountant, reportUri('ar-control'));

        $row = collect($response->json('data'))->firstWhere('code', $this->tradeReceivables->code);

        expect($row['subledger'])->toBe('0.0000')
            ->and($row['general_ledger'])->toBe('0.0000')
            ->and($row['reconciles'])->toBeTrue()
            ->and($response->json('meta.totals.reconciles'))->toBeTrue();
    });
});

describe('isolation', function (): void {
    it('never counts a sibling company’s invoices, within one workspace', function (string $report): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        app(FiscalCalendarService::class)->openYearContaining($second, CarbonImmutable::now());
        app(MembershipService::class)->grant($second, $this->accountant, $this->owner);

        $secondRevenue = Account::query()->forCompany($second->getKey())->where('code', '4100')->firstOrFail();
        rptInvoice(rptCustomer('SIBLING', null, $second), '7777.00', $second, $secondRevenue);

        rptInvoice(rptCustomer('OWN'), '1000.00');

        // The case row level security alone does not cover: both companies share a tenant_id, so only the
        // explicit company scoping keeps one entity's debtors out of the other's report.
        $response = asReportApi($this->accountant, reportUri($report));

        expect($response)->toBeEnvelope()
            ->and(json_encode($response->json('data')))->not->toContain('7777.0000');
    })->with(['outstanding-receivables', 'aged-receivables']);

    it('never counts another workspace’s invoices', function (): void {
        rptInvoice(rptCustomer('OWN'), '1000.00');

        app(TenantContext::class)->runFor($this->globex['tenant'], function (): void {
            $company = $this->globex['company'];
            app(ChartTemplateService::class)->apply($company);
            app(FiscalCalendarService::class)->openYearContaining($company, CarbonImmutable::now());

            $revenue = Account::query()->forCompany($company->getKey())->where('code', '4100')->firstOrFail();
            $customer = app(CustomerService::class)->create($company, new CustomerData(name: 'Globex Co', code: 'GLBX'));

            $today = CarbonImmutable::now()->startOfDay();
            $draft = app(SalesInvoiceService::class)->createDraft($company, new SalesInvoiceData(
                customerId: (string) $customer->getKey(),
                invoiceDate: $today,
                dueDate: $today,
                lines: [new SalesInvoiceLineData(
                    description: 'Consulting services',
                    quantity: '1',
                    unitPrice: '8888.00',
                    revenueAccountId: (string) $revenue->getKey(),
                )],
            ));

            app(SalesInvoiceService::class)->issue($draft, $this->globex['owner']);
        });

        $response = asReportApi($this->accountant, reportUri('outstanding-receivables'));

        expect($response)->toBeEnvelope()
            ->and($response->json('meta.totals.outstanding'))->toBe('1000.0000')
            ->and(json_encode($response->json('data')))->not->toContain('8888.0000');
    });
});
