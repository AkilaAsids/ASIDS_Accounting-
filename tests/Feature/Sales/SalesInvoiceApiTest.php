<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
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
use Asids\Core\Sales\Domain\Enums\SalesInvoiceStatus;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The sales invoice HTTP surface — Milestone 9A.
 *
 * What this file owns is the transport layer: authorisation, the company guard, the envelope, `Money` on the wire,
 * mass assignment, the `issue: true` transaction boundary, and the capability flags a client builds its buttons
 * from. The domain beneath it already has 156 tests across `SalesInvoiceDraftTest`, `IssueInvoiceTest`,
 * `CancelInvoiceTest`, `IssuedInvoiceImmutabilityTest` and `SalesInvoiceAuthorizationTest` — the numbering, the
 * posting map, the tax resolution and every transition rule are theirs, and re-asserting them here would put two
 * copies of one rule in the suite.
 *
 * THREE THINGS EARN MOST OF THE TESTS
 * -----------------------------------
 * **The company guard.** Nested route bindings are not parent-scoped, so `/companies/A/sales-invoices/{B's
 * invoice}` reaches a foreign invoice whenever the caller belongs to both companies — and `RequestContext` takes
 * the audit trail's `company_id` from the *url*. Issuing under the wrong company would post to B's ledger while
 * the trail said A. Every route that binds an invoice is tested for it.
 *
 * **`issue: true` rolling back.** A bookkeeper may draft but not issue. If the authorisation check sat outside the
 * transaction, their attempt would leave a draft behind on every try. The test asserts the 403 *and* that the
 * table is empty afterwards.
 *
 * **`can_cancel`.** The policy deliberately answers `hasBeenIssued()`, which is true for a cancelled invoice,
 * because it answers a capability question and leaves the particular invoice to the service. A resource copying
 * that predicate would offer Cancel on a cancelled invoice. Asserted for an owner too, since `Gate::before` grants
 * them every ability and short-circuits every state guard in the policy.
 *
 * Helpers are prefixed `invoiceApi`/`asInvoiceApi` because Pest loads every matched file into one process and
 * top-level function names are global — two files declaring the same one is a fatal error for the whole suite.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->revenue = invoiceApiAccount('4100');
    $this->receivables = invoiceApiAccount('1130');

    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'inv-acct@acme.test']);
    $this->bookkeeper = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', ['email' => 'inv-book@acme.test']);
    $this->viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer', ['email' => 'inv-view@acme.test']);

    // Permission and membership are different questions and the policy asks both.
    $memberships = app(MembershipService::class);
    foreach ([$this->accountant, $this->bookkeeper, $this->viewer] as $member) {
        $memberships->grant($this->company, $member, $this->owner);
    }

    $this->customer = app(CustomerService::class)->create($this->company, new CustomerData(
        name: 'Silva Traders',
        code: 'SILVA',
    ));
});

function invoiceApiAccount(string $code, ?Company $company = null): Account
{
    return Account::query()
        ->forCompany(($company ?? test()->company)->getKey())
        ->where('code', $code)
        ->firstOrFail();
}

/**
 * A member of the acme company, acting on its invoices.
 *
 * @param  array<string, mixed>  $payload
 */
function asInvoiceApi(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $fresh = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($fresh ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->withHeader('X-Company', test()->company->getKey())
        ->json($method, $uri, $payload);
}

function invoiceApiUri(string $suffix = '', ?Company $company = null): string
{
    $base = '/api/v1/companies/'.($company ?? test()->company)->getKey().'/sales-invoices';

    return $suffix === '' ? $base : $base.'/'.ltrim($suffix, '/');
}

/**
 * A valid create payload. Overrides are merged over the header; `lines` replaces wholesale.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoiceApiPayload(array $overrides = []): array
{
    return [
        'customer_id' => (string) test()->customer->getKey(),
        'invoice_date' => '2026-06-15',
        'lines' => [[
            'description' => 'Consulting services',
            'quantity' => '2',
            'unit_price' => '500.00',
            'revenue_account_id' => (string) test()->revenue->getKey(),
        ]],
        ...$overrides,
    ];
}

/**
 * A draft created over HTTP, so the endpoint under test built it.
 */
function invoiceApiDraft(array $overrides = []): SalesInvoice
{
    $response = asInvoiceApi(test()->accountant, 'POST', invoiceApiUri(), invoiceApiPayload($overrides));

    $response->assertStatus(201);

    return SalesInvoice::query()->findOrFail($response->json('data.id'));
}

function invoiceApiIssued(array $overrides = []): SalesInvoice
{
    $draft = invoiceApiDraft($overrides);

    asInvoiceApi(test()->accountant, 'POST', invoiceApiUri($draft->getKey().'/issue'))->assertStatus(200);

    return $draft->refresh();
}

/**
 * A company in the same workspace, fully set up, that the accountant is a member of.
 *
 * Membership is the point: without it the `company` middleware would 404 before the guard under test ran, and the
 * test would pass for the wrong reason.
 *
 * @return array{company: Company, customer: Customer, revenue: Account}
 */
function invoiceApiSiblingCompany(): array
{
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), test()->owner);

    app(ChartTemplateService::class)->apply($company);
    app(FiscalCalendarService::class)->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));
    app(MembershipService::class)->grant($company, test()->accountant, test()->owner);

    return [
        'company' => $company,
        'customer' => app(CustomerService::class)->create($company, new CustomerData(name: 'Sibling Co', code: 'SIB')),
        'revenue' => invoiceApiAccount('4100', $company),
    ];
}

/**
 * A role holding invoice *view* and nothing else, so a permission can be isolated from a role.
 *
 * Purpose-built because every built-in template that reaches these endpoints legitimately holds the capability —
 * the administrator template is defined as every tenant-grantable permission. `assign()` replaces the whole role
 * set, so the bookkeeper grant this user is created with does not survive it.
 *
 * @param  list<string>  $permissions
 */
function invoiceApiUserWith(array $permissions, string $email): User
{
    $roles = app(RoleService::class);

    $role = $roles->create(new RoleData(
        label: 'Invoice Scoped '.$email,
        description: 'Purpose-built for an authorization test.',
        permissionNames: ['organization.companies.view', ...$permissions],
    ), test()->owner);

    $user = test()->createUserWithRole(test()->acme['tenant'], 'bookkeeper', ['email' => $email]);

    app(MembershipService::class)->grant(test()->company, $user, test()->owner);
    $roles->assign($user, [$role->getKey()], test()->owner);

    return $user;
}

describe('authorization', function (): void {
    it('lets an accountant drive the whole lifecycle', function (): void {
        $created = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload());
        expect($created)->toBeEnvelope(201);

        $id = $created->json('data.id');

        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri()))->toBeEnvelope()
            ->and(asInvoiceApi($this->accountant, 'GET', invoiceApiUri($id)))->toBeEnvelope()
            ->and(asInvoiceApi($this->accountant, 'PUT', invoiceApiUri($id), ['reference' => 'PO-1']))->toBeEnvelope()
            ->and(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($id.'/issue')))->toBeEnvelope()
            ->and(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($id.'/cancel'), ['reason' => 'Wrong customer entirely.']))
            ->toBeEnvelope();
    });

    it('lets a bookkeeper read and draft', function (): void {
        expect(asInvoiceApi($this->bookkeeper, 'GET', invoiceApiUri()))->toBeEnvelope()
            ->and(asInvoiceApi($this->bookkeeper, 'POST', invoiceApiUri(), invoiceApiPayload()))->toBeEnvelope(201);
    });

    it('refuses a bookkeeper the issue endpoint', function (): void {
        $draft = invoiceApiDraft();

        // The split the permissions exist for: a bookkeeper records what was sold; someone else commits it to
        // the ledger and to the customer.
        expect(asInvoiceApi($this->bookkeeper, 'POST', invoiceApiUri($draft->getKey().'/issue')))
            ->toBeProblem('forbidden', 403);
    });

    it('refuses a bookkeeper the cancel endpoint', function (): void {
        $invoice = invoiceApiIssued();

        expect(asInvoiceApi($this->bookkeeper, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), ['reason' => 'Not mine to undo.']))
            ->toBeProblem('forbidden', 403);
    });

    it('lets a viewer read but not write', function (): void {
        $draft = invoiceApiDraft();

        expect(asInvoiceApi($this->viewer, 'GET', invoiceApiUri($draft->getKey())))->toBeEnvelope()
            ->and(asInvoiceApi($this->viewer, 'POST', invoiceApiUri(), invoiceApiPayload()))->toBeProblem('forbidden', 403)
            ->and(asInvoiceApi($this->viewer, 'PUT', invoiceApiUri($draft->getKey()), ['reference' => 'X']))->toBeProblem('forbidden', 403)
            ->and(asInvoiceApi($this->viewer, 'DELETE', invoiceApiUri($draft->getKey())))->toBeProblem('forbidden', 403);
    });

    it('refuses a role without the invoice view permission', function (): void {
        $draft = invoiceApiDraft();
        $stranger = invoiceApiUserWith([], 'inv-none@acme.test');

        expect(asInvoiceApi($stranger, 'GET', invoiceApiUri()))->toBeProblem('forbidden', 403)
            ->and(asInvoiceApi($stranger, 'GET', invoiceApiUri($draft->getKey())))->toBeProblem('forbidden', 403);
    });

    it('refuses a role that may view but not draft', function (): void {
        $reader = invoiceApiUserWith(['sales.invoices.view'], 'inv-read@acme.test');

        expect(asInvoiceApi($reader, 'GET', invoiceApiUri()))->toBeEnvelope()
            ->and(asInvoiceApi($reader, 'POST', invoiceApiUri(), invoiceApiPayload()))->toBeProblem('forbidden', 403);
    });

    it('refuses an unauthenticated caller on every route', function (): void {
        // Built through the service rather than over HTTP, because `asInvoiceApi` calls `actingAs` and Laravel
        // keeps that user for the rest of the test — an "unauthenticated" request made afterwards would quietly
        // be authenticated, and this test would pass without ever exercising the guard.
        $draft = app(SalesInvoiceService::class)->createDraft(
            $this->company,
            new SalesInvoiceData(
                customerId: (string) $this->customer->getKey(),
                invoiceDate: CarbonImmutable::parse('2026-06-15'),
                lines: [new SalesInvoiceLineData(
                    description: 'Consulting services',
                    quantity: '1',
                    unitPrice: '100.00',
                    revenueAccountId: (string) $this->revenue->getKey(),
                )],
            ),
        );

        $id = (string) $draft->getKey();

        foreach ([
            ['GET', invoiceApiUri()],
            ['POST', invoiceApiUri()],
            ['GET', invoiceApiUri($id)],
            ['PUT', invoiceApiUri($id)],
            ['DELETE', invoiceApiUri($id)],
            ['POST', invoiceApiUri($id.'/issue')],
            ['POST', invoiceApiUri($id.'/cancel')],
        ] as [$method, $uri]) {
            expect(test()->withHeader('X-Tenant', 'acme')->json($method, $uri))
                ->toBeProblem('unauthenticated', 401);
        }
    });

    it('refuses a caller with no membership of the company in the url', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        // Deliberately not asInvoiceApi(): that helper always addresses test()->company.
        $fresh = RowLevelSecurity::bypass(fn (): ?User => $this->accountant->fresh());

        $response = test()->actingAs($fresh ?? $this->accountant)
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $second->getKey())
            ->getJson(invoiceApiUri('', $second));

        expect($response)->toBeProblem('company-not-available', 404);
    });
});

describe('the company guard', function (): void {
    it('refuses a sibling company’s invoice on every route that binds one', function (): void {
        $sibling = invoiceApiSiblingCompany();

        // Created inside the sibling company, by a caller who is a member of both — which is exactly the
        // situation the policy alone cannot distinguish.
        $foreign = app(SalesInvoiceService::class)->createDraft(
            $sibling['company'],
            new SalesInvoiceData(
                customerId: (string) $sibling['customer']->getKey(),
                invoiceDate: CarbonImmutable::parse('2026-06-15'),
                lines: [new SalesInvoiceLineData(
                    description: 'Sibling work',
                    quantity: '1',
                    unitPrice: '100.00',
                    revenueAccountId: (string) $sibling['revenue']->getKey(),
                )],
            ),
        );

        $id = (string) $foreign->getKey();

        // Gated on the invoice genuinely being reachable under its own company first, so a 422 here cannot be a
        // 422 for some unrelated reason.
        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri($id, $sibling['company'])))->toBeEnvelope();

        foreach ([
            ['GET', invoiceApiUri($id), []],
            ['PUT', invoiceApiUri($id), ['reference' => 'X']],
            ['DELETE', invoiceApiUri($id), []],
            ['POST', invoiceApiUri($id.'/issue'), []],
            ['POST', invoiceApiUri($id.'/cancel'), ['reason' => 'Not under this company.']],
        ] as [$method, $uri, $payload]) {
            expect(asInvoiceApi($this->accountant, $method, $uri, $payload))
                ->toBeProblem('invoice-company-mismatch', 422);
        }
    });

    it('leaves the sibling invoice untouched after a refused issue', function (): void {
        $sibling = invoiceApiSiblingCompany();

        $foreign = app(SalesInvoiceService::class)->createDraft(
            $sibling['company'],
            new SalesInvoiceData(
                customerId: (string) $sibling['customer']->getKey(),
                invoiceDate: CarbonImmutable::parse('2026-06-15'),
                lines: [new SalesInvoiceLineData(
                    description: 'Sibling work',
                    quantity: '1',
                    unitPrice: '100.00',
                    revenueAccountId: (string) $sibling['revenue']->getKey(),
                )],
            ),
        );

        asInvoiceApi($this->accountant, 'POST', invoiceApiUri($foreign->getKey().'/issue'))
            ->assertStatus(422);

        // Nothing posted, and the audit trail was never given the chance to attribute it to acme.
        expect($foreign->refresh()->status)->toBe(SalesInvoiceStatus::Draft)
            ->and($foreign->number)->toBeNull()
            ->and($foreign->journal_entry_id)->toBeNull();
    });

    it('hides another workspace’s invoice entirely', function (): void {
        $draft = invoiceApiDraft();

        $foreignId = app(TenantContext::class)->runFor($this->globex['tenant'], function (): string {
            $company = $this->globex['company'];
            app(ChartTemplateService::class)->apply($company);
            app(FiscalCalendarService::class)->openYearContaining($company, CarbonImmutable::parse('2026-06-15'));

            $customer = app(CustomerService::class)->create($company, new CustomerData(name: 'Globex Co', code: 'GLBX'));

            return (string) app(SalesInvoiceService::class)->createDraft(
                $company,
                new SalesInvoiceData(
                    customerId: (string) $customer->getKey(),
                    invoiceDate: CarbonImmutable::parse('2026-06-15'),
                    lines: [new SalesInvoiceLineData(
                        description: 'Globex work',
                        quantity: '1',
                        unitPrice: '100.00',
                        revenueAccountId: (string) invoiceApiAccount('4100', $company)->getKey(),
                    )],
                ),
            )->getKey();
        });

        // Gated on a reachable invoice first: every unmatched url in this application 404s with the same body.
        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri($draft->getKey())))->toBeEnvelope();

        // Row level security makes the row invisible, so binding fails before any policy or guard runs — a 404
        // that reveals nothing about whether the id exists.
        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri($foreignId)))->toBeProblem('not-found', 404);
    });
});

describe('creating a draft', function (): void {
    it('returns the invoice with its computed figures as decimal strings', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload());

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.status'))->toBe('draft')
            ->and($response->json('data.status_label'))->toBe('Draft')
            // 2 × 500.00, computed by the service and never by the caller.
            ->and($response->json('data.subtotal'))->toBe('1000.0000')
            ->and($response->json('data.total'))->toBe('1000.0000')
            ->and($response->json('data.amount_due'))->toBe('1000.0000')
            ->and($response->json('data.total'))->toBeString()
            // A draft has no number, and a CHECK ties the two together.
            ->and($response->json('data.number'))->toBeNull()
            ->and($response->json('data.issued_at'))->toBeNull()
            ->and($response->json('data.journal_entry_id'))->toBeNull();
    });

    it('derives the due date from the customer’s payment terms when none is given', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload());

        expect($response->json('data.due_date'))->not->toBeNull();
    });

    it('returns the lines it created', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload());

        expect($response->json('data.lines'))->toHaveCount(1)
            ->and($response->json('data.lines.0.line_number'))->toBe(1)
            ->and($response->json('data.lines.0.quantity'))->toBe('2.0000')
            ->and($response->json('data.lines.0.unit_price'))->toBe('500.0000')
            ->and($response->json('data.lines.0.line_subtotal'))->toBe('1000.0000');
    });

    it('records who created it', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload());

        expect($response->json('data.created_by_id'))->toBe((string) $this->accountant->getKey());
    });

    it('accepts an amount sent as a JSON number', function (): void {
        // Coerced to a string before the regex sees it, so the common case is not refused as pedantry — though
        // the API documents strings, because a value like 10.005 has already been through a float by then.
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'lines' => [[
                'description' => 'Consulting services',
                'quantity' => 1,
                'unit_price' => 250.5,
                'revenue_account_id' => (string) $this->revenue->getKey(),
            ]],
        ]));

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.total'))->toBe('250.5000');
    });

    it('refuses an amount with more than four decimal places', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'lines' => [[
                'description' => 'Consulting services',
                'quantity' => '1',
                'unit_price' => '100.123456',
                'revenue_account_id' => (string) $this->revenue->getKey(),
            ]],
        ]));

        // Refused rather than rounded: silently dropping a digit is how a total stops matching its document.
        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses an invoice with no lines', function (): void {
        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload(['lines' => []])))
            ->toBeProblem('validation-failed', 422);
    });

    it('accepts a single line', function (): void {
        // One line is an ordinary invoice. The two-line minimum on a journal entry has no analogue here.
        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload()))->toBeEnvelope(201);
    });

    it('refuses a customer belonging to another company', function (): void {
        $sibling = invoiceApiSiblingCompany();

        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'customer_id' => (string) $sibling['customer']->getKey(),
        ]));

        // The service names the actual problem rather than the request returning a generic "invalid".
        expect($response)->toBeProblem('customer-outside-company', 422);
    });

    it('refuses a revenue account belonging to another company', function (): void {
        $sibling = invoiceApiSiblingCompany();

        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'lines' => [[
                'description' => 'Consulting services',
                'quantity' => '1',
                'unit_price' => '100.00',
                'revenue_account_id' => (string) $sibling['revenue']->getKey(),
            ]],
        ]));

        expect($response)->toBeProblem('revenue-account-outside-company', 422);
    });

    it('refuses a line carrying both discount forms', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'lines' => [[
                'description' => 'Consulting services',
                'quantity' => '1',
                'unit_price' => '100.00',
                'revenue_account_id' => (string) $this->revenue->getKey(),
                'discount_percent' => '10',
                'discount_amount' => '5.00',
            ]],
        ]));

        // A percentage a salesperson negotiated and a fixed amount a manager approved are different claims.
        expect($response)->toBeProblem('invoice-line-two-discounts', 422);
    });

    it('refuses a due date before the invoice date', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'due_date' => '2026-06-01',
        ]));

        expect($response)->toBeProblem('due-date-before-invoice-date', 422);
    });

    it('refuses a tax code named as a uuid rather than a code', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'lines' => [[
                'description' => 'Consulting services',
                'quantity' => '1',
                'unit_price' => '100.00',
                'revenue_account_id' => (string) $this->revenue->getKey(),
                // A uuid is 36 characters; the field is capped at 32 because a tax *code* is short. Accepting an
                // id would let a caller bypass the only mechanism that knows which effective-dated row applies.
                'tax_code' => (string) $this->revenue->getKey(),
            ]],
        ]));

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('ignores every computed and record-owned field a caller tries to set', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload([
            'status' => 'issued',
            'number' => 'INV-FORGED-0001',
            'total' => '999999.0000',
            'subtotal' => '999999.0000',
            'tax_total' => '999999.0000',
            'amount_paid' => '500.0000',
            'amount_due' => '0.0000',
            'currency_code' => 'USD',
            'journal_entry_id' => (string) $this->revenue->getKey(),
            'issued_at' => '2020-01-01T00:00:00+00:00',
            'issued_by_id' => (string) $this->owner->getKey(),
            'company_id' => (string) $this->globex['company']->getKey(),
            'created_by_id' => (string) $this->owner->getKey(),
            'cancelled_at' => '2020-01-01T00:00:00+00:00',
        ]));

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.status'))->toBe('draft')
            ->and($response->json('data.number'))->toBeNull()
            ->and($response->json('data.total'))->toBe('1000.0000')
            ->and($response->json('data.amount_paid'))->toBe('0.0000')
            ->and($response->json('data.currency_code'))->toBe($this->company->base_currency_code)
            ->and($response->json('data.journal_entry_id'))->toBeNull()
            ->and($response->json('data.issued_at'))->toBeNull()
            ->and($response->json('data.issued_by_id'))->toBeNull()
            ->and($response->json('data.company_id'))->toBe((string) $this->company->getKey())
            ->and($response->json('data.created_by_id'))->toBe((string) $this->accountant->getKey())
            ->and($response->json('data.cancelled_at'))->toBeNull();
    });
});

describe('drafting and issuing in one call', function (): void {
    it('issues immediately when asked', function (): void {
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload(['issue' => true]));

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.status'))->toBe('issued')
            ->and($response->json('data.number'))->toBe('INV-2026-06-0001')
            ->and($response->json('data.issued_at'))->not->toBeNull()
            ->and($response->json('data.issued_by_id'))->toBe((string) $this->accountant->getKey())
            ->and($response->json('data.journal_entry_id'))->not->toBeNull();
    });

    it('leaves a draft when the flag is absent or false', function (): void {
        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload())->json('data.status'))
            ->toBe('draft')
            ->and(asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload(['issue' => false]))->json('data.status'))
            ->toBe('draft');
    });

    it('rolls the draft back when the caller may not issue', function (): void {
        $before = SalesInvoice::query()->forCompany((string) $this->company->getKey())->count();

        $response = asInvoiceApi($this->bookkeeper, 'POST', invoiceApiUri(), invoiceApiPayload(['issue' => true]));

        // 403 rather than 422, because the policy sees a real invoice — and *no row left behind*, because the
        // authorisation check runs inside the transaction. Without that, every attempt would deposit a draft.
        expect($response)->toBeProblem('forbidden', 403)
            ->and(SalesInvoiceStatus::tryFrom('draft'))->not->toBeNull();

        expect(SalesInvoice::query()->forCompany((string) $this->company->getKey())->count())->toBe($before);
    });

    it('rolls the draft back when issuing is refused by the domain', function (): void {
        // The receivable account made unpostable after the chart was applied: drafting does not care, issuing
        // does. The refusal must take the draft with it.
        DB::table('accounts')
            ->where('id', $this->receivables->getKey())
            ->update(['is_postable' => false]);

        $before = SalesInvoice::query()->forCompany((string) $this->company->getKey())->count();

        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri(), invoiceApiPayload(['issue' => true]));

        expect($response->getStatusCode())->toBe(422)
            ->and(SalesInvoice::query()->forCompany((string) $this->company->getKey())->count())->toBe($before)
            ->and(JournalEntry::query()->forCompany((string) $this->company->getKey())->count())->toBe(0);
    });
});

describe('reading', function (): void {
    it('paginates the index', function (): void {
        invoiceApiDraft();
        invoiceApiDraft();

        $response = asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?per_page=1');

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->toHaveCount(1)
            ->and($response->json('meta.pagination.total'))->toBe(2)
            ->and($response->json('meta.pagination.per_page'))->toBe(1)
            ->and($response->json('meta.pagination.last_page'))->toBe(2);
    });

    it('omits lines from the index until they are asked for', function (): void {
        invoiceApiDraft();

        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri())->json('data.0.lines'))->toBeNull()
            ->and(asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?include=lines')->json('data.0.lines'))
            ->toHaveCount(1);
    });

    it('always includes a customer summary', function (): void {
        invoiceApiDraft();

        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri())->json('data.0.customer.code'))->toBe('SILVA');
    });

    it('filters by status', function (): void {
        invoiceApiDraft();
        invoiceApiIssued();

        $drafts = asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?filter[status]=draft');

        expect($drafts->json('data'))->toHaveCount(1)
            ->and($drafts->json('data.0.status'))->toBe('draft');
    });

    it('filters by customer', function (): void {
        invoiceApiDraft();

        $other = app(CustomerService::class)->create($this->company, new CustomerData(name: 'Perera', code: 'PER'));
        invoiceApiDraft(['customer_id' => (string) $other->getKey()]);

        $response = asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?filter[customer_id]='.$other->getKey());

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.customer.code'))->toBe('PER');
    });

    it('refuses a customer filter that is not an identifier', function (): void {
        // Without the guard this would reach a where() on a uuid column and surface as a Postgres 22P02 rendered
        // as a generic 500.
        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?filter[customer_id]=not-a-uuid'))
            ->toBeProblem('invalid-customer-id-filter', 422);
    });

    it('refuses a sort column that is not allow-listed', function (): void {
        // `?sort=` reaches an ORDER BY, so anything outside the list would be an injection and an
        // information-disclosure vector.
        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?sort=amount_paid')->getStatusCode())
            ->toBe(422);
    });

    it('searches the number and the reference, not the customer name', function (): void {
        $issued = invoiceApiIssued();
        invoiceApiDraft(['reference' => 'PO-8842']);

        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?q='.$issued->number)->json('data'))->toHaveCount(1)
            ->and(asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?q=PO-8842')->json('data'))->toHaveCount(1)
            // The customer's name is not searched — filtering by customer_id covers that need without a join.
            ->and(asInvoiceApi($this->accountant, 'GET', invoiceApiUri().'?q=Silva')->json('data'))->toHaveCount(0);
    });

    it('returns the lines and the tax code on a single invoice', function (): void {
        $draft = invoiceApiDraft();

        $response = asInvoiceApi($this->accountant, 'GET', invoiceApiUri($draft->getKey()));

        expect($response->json('data.lines'))->toHaveCount(1)
            ->and($response->json('data.lines.0'))->toHaveKeys([
                'id', 'line_number', 'description', 'quantity', 'unit_price',
                'line_subtotal', 'tax_code_id', 'tax_rate', 'tax_amount', 'line_total',
                'revenue_account_id',
            ]);
    });
});

describe('updating a draft', function (): void {
    it('changes only what was sent', function (): void {
        $draft = invoiceApiDraft(['reference' => 'PO-1']);

        $response = asInvoiceApi($this->accountant, 'PUT', invoiceApiUri($draft->getKey()), ['notes' => 'Rush job']);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.notes'))->toBe('Rush job')
            // Omitted, so untouched.
            ->and($response->json('data.reference'))->toBe('PO-1');
    });

    it('clears a nullable field when null is sent explicitly', function (): void {
        $draft = invoiceApiDraft(['reference' => 'PO-1']);

        $response = asInvoiceApi($this->accountant, 'PUT', invoiceApiUri($draft->getKey()), ['reference' => null]);

        // The distinction the attribute-array signature exists for: omitted and explicitly-null are different
        // instructions, and without that a reference would be permanent once set.
        expect($response->json('data.reference'))->toBeNull();
    });

    it('replaces every line when lines are supplied', function (): void {
        $draft = invoiceApiDraft();

        $response = asInvoiceApi($this->accountant, 'PUT', invoiceApiUri($draft->getKey()), [
            'lines' => [
                ['description' => 'A', 'quantity' => '1', 'unit_price' => '10.00', 'revenue_account_id' => (string) $this->revenue->getKey()],
                ['description' => 'B', 'quantity' => '1', 'unit_price' => '20.00', 'revenue_account_id' => (string) $this->revenue->getKey()],
            ],
        ]);

        expect($response->json('data.lines'))->toHaveCount(2)
            ->and($response->json('data.total'))->toBe('30.0000');
    });

    it('refuses to update an issued invoice', function (): void {
        $invoice = invoiceApiIssued();

        expect(asInvoiceApi($this->accountant, 'PUT', invoiceApiUri($invoice->getKey()), ['notes' => 'Too late']))
            ->toBeProblem('invoice-not-editable', 422);
    });
});

describe('deleting a draft', function (): void {
    it('removes it outright', function (): void {
        $draft = invoiceApiDraft();

        asInvoiceApi($this->accountant, 'DELETE', invoiceApiUri($draft->getKey()))->assertStatus(204);

        // Hard deletion: a never-issued draft is not an accounting document, so no tombstone is kept.
        expect(SalesInvoice::query()->whereKey($draft->getKey())->exists())->toBeFalse();
    });

    it('refuses to delete an issued invoice', function (): void {
        $invoice = invoiceApiIssued();

        expect(asInvoiceApi($this->accountant, 'DELETE', invoiceApiUri($invoice->getKey())))
            ->toBeProblem('invoice-not-editable', 422);

        expect(SalesInvoice::query()->whereKey($invoice->getKey())->exists())->toBeTrue();
    });

    it('offers no restore route', function (): void {
        $draft = invoiceApiDraft();

        asInvoiceApi($this->accountant, 'DELETE', invoiceApiUri($draft->getKey()))->assertStatus(204);

        // There is no soft-delete column to restore from, so the route deliberately does not exist.
        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($draft->getKey().'/restore'))->getStatusCode())
            ->toBe(404);
    });
});

describe('issuing', function (): void {
    it('numbers the invoice and posts a balanced entry', function (): void {
        $draft = invoiceApiDraft();

        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri($draft->getKey().'/issue'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.status'))->toBe('issued')
            ->and($response->json('data.number'))->toBe('INV-2026-06-0001');

        $entry = JournalEntry::query()->with('lines')->findOrFail($response->json('data.journal_entry_id'));

        expect($entry->lines)->toHaveCount(2)
            // ADR 0010 identifies the receivable as line 1. The API must not disturb it.
            ->and((string) $entry->lines->first()->account_id)->toBe((string) $this->receivables->getKey())
            ->and($entry->lines->first()->line_number)->toBe(1);
    });

    it('refuses a second issue', function (): void {
        $invoice = invoiceApiIssued();

        // 403, not 422, and that is the existing policy design rather than a surprise: `SalesInvoicePolicy::issue()`
        // guards on `isDraft()`, so for anyone who is not a workspace owner the capability check answers first and
        // the service is never reached. The refusal is correct either way; only its shape differs by who asks.
        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/issue')))
            ->toBeProblem('forbidden', 403);

        expect(JournalEntry::query()->where('source_id', (string) $invoice->getKey())->count())->toBe(1);
    });

    it('refuses a second issue for an owner too, as a domain refusal', function (): void {
        $invoice = invoiceApiIssued();

        // The other half of the same rule, and the reason the service check exists at all. `Gate::before` grants
        // an owner every ability, so the policy's `isDraft()` guard is short-circuited for them and the request
        // reaches `SalesInvoiceService::issue()` — which refuses it by name. A state precondition expressed only
        // as a policy would be silently skipped for the one person most able to do damage.
        $response = test()->actingAs(RowLevelSecurity::bypass(fn (): ?User => $this->owner->fresh()) ?? $this->owner)
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $this->company->getKey())
            ->postJson(invoiceApiUri($invoice->getKey().'/issue'));

        expect($response)->toBeProblem('invoice-not-a-draft', 422);

        expect(JournalEntry::query()->where('source_id', (string) $invoice->getKey())->count())->toBe(1);
    });
});

describe('cancelling', function (): void {
    it('reverses the posting and keeps the number', function (): void {
        $invoice = invoiceApiIssued();
        $number = $invoice->number;

        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), [
            'reason' => 'Raised against the wrong customer.',
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.status'))->toBe('cancelled')
            // The document keeps its number: nothing is deleted and nothing is edited.
            ->and($response->json('data.number'))->toBe($number)
            ->and($response->json('data.cancelled_at'))->not->toBeNull()
            ->and($response->json('data.cancellation_reason'))->toBe('Raised against the wrong customer.')
            ->and($response->json('data.cancelled_by_id'))->toBe((string) $this->accountant->getKey());

        // Both entries remain in the ledger — the original and its mirror.
        expect(JournalEntry::query()->forCompany((string) $this->company->getKey())->count())->toBe(2);
    });

    it('requires a reason', function (): void {
        $invoice = invoiceApiIssued();

        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), []))
            ->toBeProblem('validation-failed', 422)
            ->and(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), ['reason' => 'no']))
            ->toBeProblem('validation-failed', 422);
    });

    it('caps the reason at the width of the ledger column', function (): void {
        $invoice = invoiceApiIssued();

        // 255, not the 500 the sales column would hold: the same string is written to
        // `journal_entries.reversal_reason`, which is varchar(255). A longer reason would be accepted by one
        // column and refused by the other, mid-transaction, as a database error rather than a message.
        $response = asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), [
            'reason' => str_repeat('a', 256),
        ]);

        expect($response)->toBeProblem('validation-failed', 422);

        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), [
            'reason' => str_repeat('a', 255),
        ]))->toBeEnvelope();
    });

    it('refuses to cancel a draft', function (): void {
        $draft = invoiceApiDraft();

        // 403 for a non-owner, because `SalesInvoicePolicy::cancel()` guards on `hasBeenIssued()` and answers
        // before the service can. Same reasoning as the second-issue pair above.
        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($draft->getKey().'/cancel'), ['reason' => 'Never issued.']))
            ->toBeProblem('forbidden', 403);
    });

    it('refuses to cancel a draft for an owner too, as a domain refusal', function (): void {
        $draft = invoiceApiDraft();

        $response = test()->actingAs(RowLevelSecurity::bypass(fn (): ?User => $this->owner->fresh()) ?? $this->owner)
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $this->company->getKey())
            ->postJson(invoiceApiUri($draft->getKey().'/cancel'), ['reason' => 'Never issued.']);

        expect($response)->toBeProblem('invoice-not-issued', 422)
            ->and($draft->refresh()->status)->toBe(SalesInvoiceStatus::Draft);
    });

    it('refuses to cancel twice', function (): void {
        $invoice = invoiceApiIssued();

        asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), ['reason' => 'First cancellation.'])
            ->assertStatus(200);

        expect(asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), ['reason' => 'Second attempt.']))
            ->toBeProblem('invoice-already-cancelled', 422);
    });
});

describe('capabilities', function (): void {
    it('offers an accountant everything a draft allows', function (): void {
        $draft = invoiceApiDraft();

        $capabilities = asInvoiceApi($this->accountant, 'GET', invoiceApiUri($draft->getKey()))->json('data.capabilities');

        expect($capabilities)->toBe([
            'can_update' => true,
            'can_delete' => true,
            'can_issue' => true,
            'can_cancel' => false,
        ]);
    });

    it('withholds issue from a bookkeeper', function (): void {
        $draft = invoiceApiDraft();

        $capabilities = asInvoiceApi($this->bookkeeper, 'GET', invoiceApiUri($draft->getKey()))->json('data.capabilities');

        expect($capabilities['can_update'])->toBeTrue()
            ->and($capabilities['can_issue'])->toBeFalse();
    });

    it('withholds everything from a viewer', function (): void {
        $draft = invoiceApiDraft();

        expect(asInvoiceApi($this->viewer, 'GET', invoiceApiUri($draft->getKey()))->json('data.capabilities'))
            ->toBe([
                'can_update' => false,
                'can_delete' => false,
                'can_issue' => false,
                'can_cancel' => false,
            ]);
    });

    it('turns an issued invoice read-only and offers cancel instead', function (): void {
        $invoice = invoiceApiIssued();

        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri($invoice->getKey()))->json('data.capabilities'))
            ->toBe([
                'can_update' => false,
                'can_delete' => false,
                'can_issue' => false,
                'can_cancel' => true,
            ]);
    });

    it('offers nothing on a cancelled invoice', function (): void {
        $invoice = invoiceApiIssued();

        asInvoiceApi($this->accountant, 'POST', invoiceApiUri($invoice->getKey().'/cancel'), ['reason' => 'Cancelled for the test.'])
            ->assertStatus(200);

        // The one the policy would get wrong: `SalesInvoicePolicy::cancel()` asks `hasBeenIssued()`, which is
        // true for a cancelled invoice. A resource copying that predicate would offer a Cancel button here.
        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri($invoice->getKey()))->json('data.capabilities'))
            ->toBe([
                'can_update' => false,
                'can_delete' => false,
                'can_issue' => false,
                'can_cancel' => false,
            ]);
    });

    it('does not report an owner as able to issue an already-issued invoice', function (): void {
        $invoice = invoiceApiIssued();

        // `Gate::before` grants an owner every ability outright, so every state guard inside the policy is
        // short-circuited for them. Asking the gate alone would report `can_issue: true` here.
        $capabilities = test()->actingAs(RowLevelSecurity::bypass(fn (): ?User => $this->owner->fresh()) ?? $this->owner)
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $this->company->getKey())
            ->getJson(invoiceApiUri($invoice->getKey()))
            ->json('data.capabilities');

        expect($capabilities['can_issue'])->toBeFalse()
            ->and($capabilities['can_update'])->toBeFalse()
            ->and($capabilities['can_cancel'])->toBeTrue();
    });
});

describe('isolation', function (): void {
    it('never lists a sibling company’s invoices', function (): void {
        $sibling = invoiceApiSiblingCompany();

        app(SalesInvoiceService::class)->createDraft(
            $sibling['company'],
            new SalesInvoiceData(
                customerId: (string) $sibling['customer']->getKey(),
                invoiceDate: CarbonImmutable::parse('2026-06-15'),
                lines: [new SalesInvoiceLineData(
                    description: 'Sibling work',
                    quantity: '1',
                    unitPrice: '7777.00',
                    revenueAccountId: (string) $sibling['revenue']->getKey(),
                )],
            ),
        );

        invoiceApiDraft();

        // Both companies share a tenant_id, so only the explicit company scoping keeps them apart.
        $response = asInvoiceApi($this->accountant, 'GET', invoiceApiUri());

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->toHaveCount(1)
            ->and(json_encode($response->json('data')))->not->toContain('7777.0000');
    });

    it('exposes no internal fields on the wire', function (): void {
        $draft = invoiceApiDraft();

        expect(asInvoiceApi($this->accountant, 'GET', invoiceApiUri($draft->getKey())))
            ->toNotExposeFields('tenant_id');
    });
});
