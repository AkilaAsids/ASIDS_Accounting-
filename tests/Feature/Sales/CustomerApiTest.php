<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\BranchService;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * The customer HTTP surface — Lane A of the Sales HTTP API (`docs/SALES-HTTP-API-DESIGN.md` §4).
 *
 * Written test-first, before the routes, controller, form requests or resource exist: this suite is
 * the acceptance contract Lane A is built against, not a description of what is there today. Every
 * request below targets a URI or a route name the design names explicitly, so today a 404 means
 * exactly "not built yet" — the assertions describe what the endpoint owes its caller once it exists.
 *
 * The harness mirrors `tests/Feature/Accounting/AccountingApiTest.php` (two workspaces, an
 * accountant/bookkeeper/viewer with memberships, `toBeEnvelope`/`toBeProblem`) and the authorization
 * conventions `tests/Feature/Sales/SalesInvoiceAuthorizationTest.php` established for this module.
 * Helper names below are deliberately distinct from every other Sales test file's helpers
 * (`CustomerTest.php`'s `withReceivables()`, `TaxCodeApiTest.php`'s equivalents, etc.) because Pest
 * loads every matched file into one process, and two files declaring the same top-level function name
 * would be a fatal error the moment both are run together — as the QA verification command for this
 * change does.
 *
 * A trap worth naming because two of the isolation tests below were originally written straight into
 * it: every undefined route in this application 404s with the identical generic
 * `{"type": ".../not-found", ...}` body and an empty `data`. A cross-tenant isolation assertion phrased
 * only as "the response does not contain the foreign row" or "the response is a 404" is satisfied by
 * that generic 404 today for the *wrong* reason, before any route exists — it would stay green through
 * a broken implementation just as easily as a correct one. Each such assertion below is gated behind a
 * positive check that only a genuinely working endpoint can satisfy, so a pass is never accidental.
 *
 * One thing this file does *not* need to be true yet: `CustomerService::update()` still takes a
 * `CustomerData` DTO on this branch (Lane C lands the attribute-array signature the I3 tests below
 * depend on). That is irrelevant here because there is no controller yet to call it — every test below
 * fails on the missing route, never on the service underneath it.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'cust-acct@acme.test']);
    $this->bookkeeper = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', ['email' => 'cust-book@acme.test']);
    $this->viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer', ['email' => 'cust-view@acme.test']);

    // Permission and membership are different questions and the policies ask both.
    $memberships = app(MembershipService::class);
    foreach ([$this->accountant, $this->bookkeeper, $this->viewer] as $member) {
        $memberships->grant($this->company, $member, $this->owner);
    }

    // Needed for the receivable-account and branch fixtures the I3 and validation tests build.
    app(ChartTemplateService::class)->apply($this->company);

    $this->receivables = Account::query()->forCompany($this->company->getKey())->where('code', '1130')->firstOrFail();
    $this->revenue = Account::query()->forCompany($this->company->getKey())->where('code', '4100')->firstOrFail();
    $this->header = Account::query()->forCompany($this->company->getKey())->where('code', '1100')->firstOrFail();
});

/**
 * A member of the acme company, acting on its customers.
 */
function asCustomerApi(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $fresh = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($fresh ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->withHeader('X-Company', test()->company->getKey())
        ->json($method, $uri, $payload);
}

function customerUri(string $suffix = ''): string
{
    $base = '/api/v1/companies/'.test()->company->getKey().'/customers';

    return $suffix === '' ? $base : $base.'/'.ltrim($suffix, '/');
}

/**
 * A customer built through the real service, following the house convention of constructing
 * fixtures through application code rather than inserting rows.
 *
 * @param  array{name?: string, code?: string}  $overrides
 */
function createCustomer(array $overrides = []): Customer
{
    return app(CustomerService::class)->create(test()->company, new CustomerData(
        name: $overrides['name'] ?? 'Silva Traders',
        code: $overrides['code'] ?? null,
    ));
}

/**
 * Rebinds the receivables probe so the archive/delete/code-lock rules can be exercised over HTTP
 * before Milestone 5 binds a real implementation — the technique `CustomerTest::withReceivables()`
 * uses at the service layer, under a distinct name because both files load in the same test run.
 */
function withCustomerReceivables(string $balance, bool $hasInvoice = true): void
{
    app()->bind(ReceivableBalanceProbe::class, fn (): ReceivableBalanceProbe => new class($balance, $hasInvoice) implements ReceivableBalanceProbe
    {
        public function __construct(private string $balance, private bool $hasInvoice) {}

        public function outstandingBalance(Customer $customer): string
        {
            return $this->balance;
        }

        public function hasAnyInvoice(Customer $customer): bool
        {
            return $this->hasInvoice;
        }
    });

    // The service is a singleton holding the probe it was built with, so it has to be forgotten and
    // re-resolved — from a fresh HTTP request, that happens automatically — for the new binding to
    // reach it.
    app()->forgetInstance(CustomerService::class);
}

/**
 * A customer with `branch_id`, `receivable_account_id` and `credit_limit` all set to a known value,
 * so a PUT can prove "omitted" and "null" behave differently for each of them independently — the I3
 * contract (`docs/SALES-HTTP-API-DESIGN.md` §3.1, §9).
 *
 * Built directly through the factory rather than the service: `CustomerService::create()` only ever
 * assigns `branch_id`/`receivable_account_id` when the caller supplies a value, so the factory is the
 * only way to get a fixture with all three already populated. Factories run unguarded, so the columns
 * being absent from `Customer::$fillable` does not matter here.
 *
 * @return array{customer: Customer, branch: Branch, account: Account}
 */
function customerWithEverythingSet(): array
{
    $branch = app(BranchService::class)->create(test()->company, ['name' => 'Kandy Branch', 'code' => 'KANDY']);

    $customer = Customer::factory()->create([
        'company_id' => test()->company->getKey(),
        'branch_id' => $branch->getKey(),
        'receivable_account_id' => test()->receivables->getKey(),
        'credit_limit' => '25000.0000',
    ]);

    return ['customer' => $customer, 'branch' => $branch, 'account' => test()->receivables];
}

describe('route names', function (): void {
    it('registers every customer route under the api.v1.companies.customers name', function (): void {
        foreach ([
            'index', 'store', 'show', 'update', 'destroy',
            'archive', 'restore', 'deactivate', 'reactivate',
        ] as $action) {
            expect(Route::has("api.v1.companies.customers.{$action}"))
                ->toBeTrue("expected route api.v1.companies.customers.{$action} to be registered");
        }
    });
});

describe('authorization', function (): void {
    it('lets an accountant create a customer', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), ['name' => 'New Customer']);

        expect($response)->toBeEnvelope(201);
    });

    it('lets a bookkeeper create a customer', function (): void {
        // Unlike tax codes, `sales.customers.manage` is held by both templates (RoleTemplate.php
        // :98-99, :140-141): entering day-to-day sales means creating the customer being sold to.
        $response = asCustomerApi($this->bookkeeper, 'POST', customerUri(), ['name' => 'New Customer']);

        expect($response)->toBeEnvelope(201);
    });

    it('refuses a viewer the right to create a customer', function (): void {
        $response = asCustomerApi($this->viewer, 'POST', customerUri(), ['name' => 'New Customer']);

        expect($response)->toBeProblem('forbidden', 403);
    });

    it('lets a viewer read a customer', function (): void {
        $customer = createCustomer();

        $response = asCustomerApi($this->viewer, 'GET', customerUri((string) $customer->getKey()));

        expect($response)->toBeEnvelope();
    });

    it('refuses a viewer every write action on an existing customer', function (): void {
        $id = (string) createCustomer()->getKey();

        foreach (['archive', 'deactivate'] as $action) {
            expect(asCustomerApi($this->viewer, 'POST', customerUri("{$id}/{$action}"))->getStatusCode())->toBe(403);
        }

        expect(asCustomerApi($this->viewer, 'PUT', customerUri($id), ['name' => 'Changed'])->getStatusCode())->toBe(403)
            ->and(asCustomerApi($this->viewer, 'DELETE', customerUri($id))->getStatusCode())->toBe(403);
    });

    it('lets an accountant restore a deleted customer but refuses a viewer', function (): void {
        $forViewer = createCustomer(['name' => 'For Viewer Attempt', 'code' => 'VIEW-RESTORE']);
        app(CustomerService::class)->delete($forViewer);

        $forAccountant = createCustomer(['name' => 'For Accountant', 'code' => 'ACCT-RESTORE']);
        app(CustomerService::class)->delete($forAccountant);

        expect(asCustomerApi($this->viewer, 'POST', customerUri($forViewer->getKey().'/restore'))->getStatusCode())->toBe(403)
            ->and(asCustomerApi($this->accountant, 'POST', customerUri($forAccountant->getKey().'/restore'))->getStatusCode())->toBe(200);
    });

    it('lets the owner update a customer with no explicit grant of the permission', function (): void {
        $customer = createCustomer();

        // Gate::before grants a tenant owner every ability; asserted so the behaviour is recorded
        // here too, the way SalesInvoiceAuthorizationTest records it for invoices.
        $response = asCustomerApi($this->owner, 'PUT', customerUri((string) $customer->getKey()), ['name' => 'Owner Edit']);

        expect($response)->toBeEnvelope();
    });
});

describe('isolation', function (): void {
    it('never returns another workspace’s customers from the index', function (): void {
        $ownCustomer = createCustomer(['name' => 'Acme Co']);

        $foreignCustomer = app(TenantContext::class)->runFor(
            $this->globex['tenant'],
            fn () => app(CustomerService::class)->create($this->globex['company'], new CustomerData(name: 'Globex Co')),
        );

        $response = asCustomerApi($this->accountant, 'GET', customerUri());

        // Gated on toBeEnvelope() and on containing the caller's own customer first: without both,
        // "does not contain the foreign id" would pass just as well against an empty 404 body, which
        // proves nothing about isolation — every unmatched url in the application 404s that way.
        expect($response)->toBeEnvelope();

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toContain((string) $ownCustomer->getKey())
            ->and($ids)->not->toContain((string) $foreignCustomer->getKey());
    });

    it('hides another workspace’s customer from a direct show', function (): void {
        $ownCustomer = createCustomer(['name' => 'Acme Co']);

        $foreignCustomer = app(TenantContext::class)->runFor(
            $this->globex['tenant'],
            fn () => app(CustomerService::class)->create($this->globex['company'], new CustomerData(name: 'Globex Co')),
        );

        // Proven against a customer that genuinely is reachable first: a bare 404 for an unmatched
        // url would otherwise satisfy the assertion below for the wrong reason, since every undefined
        // route in the application 404s with exactly this same generic shape.
        expect(asCustomerApi($this->accountant, 'GET', customerUri((string) $ownCustomer->getKey()))->getStatusCode())
            ->toBe(200);

        $response = asCustomerApi($this->accountant, 'GET', customerUri((string) $foreignCustomer->getKey()));

        expect($response)->toBeProblem('not-found', 404);
    });

    it('refuses a caller with no membership of the company named in the url', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);

        // Deliberately not asCustomerApi(): that helper always addresses test()->company, and this
        // test needs to name a company the accountant has no membership of at all.
        $response = test()->actingAs(RowLevelSecurity::bypass(fn () => $this->accountant->fresh()))
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $second->getKey())
            ->getJson('/api/v1/companies/'.$second->getKey().'/customers');

        expect($response)->toBeProblem('company-not-available', 404);
    });

    it('refuses a member of a sibling company reaching this company’s customer', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $foreignCustomer = app(CustomerService::class)->create($second, new CustomerData(name: 'Sibling Co'));

        // $this->accountant is a member of $this->company (the url) but not of $second, and the
        // customer belongs to $second — route-model binding is not parent-scoped (design §2.2), so
        // this reaches the policy rather than 404ing at the middleware.
        $response = asCustomerApi($this->accountant, 'GET', customerUri((string) $foreignCustomer->getKey()));

        expect($response)->toBeProblem('forbidden', 403);
    });
});

describe('the index', function (): void {
    it('lists customers for the company in an envelope with pagination meta', function (): void {
        $customer = createCustomer();

        $response = asCustomerApi($this->accountant, 'GET', customerUri());

        expect($response)->toBeEnvelope()
            ->and($response->json('meta.pagination'))->not->toBeNull()
            ->and(collect($response->json('data'))->pluck('id')->all())->toContain((string) $customer->getKey());
    });

    it('refuses an unsupported sort column', function (): void {
        $response = asCustomerApi($this->accountant, 'GET', customerUri().'?sort=credit_limit');

        expect($response)->toBeProblem('unsupported-sort', 422);
    });

    it('returns a clean 422 rather than a 500 for a malformed branch_id filter', function (): void {
        // Security review S1: a non-UUID `filter[branch_id]` used to flow straight into
        // `where('branch_id', ...)` on a uuid column and blow up as a Postgres 22P02, rendered as a
        // generic 500. `branch_id` is validated as a UUID shape before it reaches the query, the same
        // convention `ResolveActiveCompany::requestedCompanyId()` already applies to `X-Company`.
        $response = asCustomerApi($this->accountant, 'GET', customerUri().'?filter[branch_id]=not-a-uuid');

        expect($response)->toBeProblem('invalid-branch-id-filter', 422);
    });
});

describe('creating a customer', function (): void {
    it('creates a customer with a generated code', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), ['name' => 'Silva Traders']);

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.code'))->toStartWith('C-')
            ->and($response->json('data.status'))->toBe('active')
            ->and($response->json('data.capabilities.can_update'))->toBeTrue();
    });

    it('accepts a supplied code', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), ['name' => 'Silva', 'code' => 'SILVA']);

        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.code'))->toBe('SILVA');
    });

    it('refuses a blank name', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), ['name' => '']);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses creating a vat-registered customer without a vat number', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'is_vat_registered' => true,
        ]);

        expect($response)->toBeProblem('vat-registration-number-required', 422);
    });

    it('refuses negative payment terms at the request boundary', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'payment_terms_days' => -1,
        ]);

        // StoreCustomerRequest's own `min:0` rule (design §4.2) catches this before the service's
        // `negative-payment-terms` check would ever run — see the note on this endpoint's problem
        // table in the report back to the Delivery Manager.
        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses a negative credit limit and names the actual problem rather than a generic validation failure', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'credit_limit' => '-100.00',
        ]);

        // The request-level regex deliberately lets a leading minus through (design §4.2: "the minus
        // is allowed through so the service's negative-credit-limit names the actual problem"), so
        // this is the service speaking, not the generic validator.
        expect($response)->toBeProblem('negative-credit-limit', 422);
    });

    it('rejects a non-numeric credit limit before it reaches the service', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'credit_limit' => '500,000',
        ]);

        // Flagged for the design: §4.4 lists `credit-limit-not-a-number` as a problem code this
        // endpoint can produce, but the request rule's regex (`^-?\d{1,15}(\.\d{1,4})?$`) rejects any
        // non-numeric shape before the service's own check ever runs, so that code is not actually
        // observable through HTTP as specified — a caller gets this generic failure instead.
        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses a branch belonging to another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        $foreignBranch = app(BranchService::class)->create($second, ['name' => 'Foreign Branch', 'code' => 'FOREIGN']);

        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'branch_id' => (string) $foreignBranch->getKey(),
        ]);

        expect($response)->toBeProblem('branch-outside-company', 422);
    });

    it('refuses a receivable account belonging to another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);
        $foreignAccount = Account::query()->forCompany($second->getKey())->where('code', '1130')->firstOrFail();

        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'receivable_account_id' => (string) $foreignAccount->getKey(),
        ]);

        expect($response)->toBeProblem('account-outside-company', 422);
    });

    it('refuses a receivable account that is not an asset', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'receivable_account_id' => (string) $this->revenue->getKey(),
        ]);

        expect($response)->toBeProblem('receivable-account-wrong-type', 422);
    });

    it('refuses a non-postable header account as the receivable account', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'receivable_account_id' => (string) $this->header->getKey(),
        ]);

        expect($response)->toBeProblem('receivable-account-not-postable', 422);
    });

    it('refuses a duplicate customer code regardless of case', function (): void {
        createCustomer(['code' => 'DUPE']);

        $response = asCustomerApi($this->accountant, 'POST', customerUri(), ['name' => 'Other', 'code' => 'dupe']);

        expect($response)->toBeProblem('duplicate-resource', 409);
    });

    it('does not accept a status field from the client', function (): void {
        $response = asCustomerApi($this->accountant, 'POST', customerUri(), [
            'name' => 'Silva',
            'status' => 'archived',
        ]);

        // Ignored rather than honoured — a customer's state moves only through the named action
        // endpoints (design §4.1), never a settable field on create or update.
        expect($response)->toBeEnvelope(201)
            ->and($response->json('data.status'))->toBe('active');
    });
});

describe('showing and updating a customer', function (): void {
    it('shows a customer', function (): void {
        $customer = createCustomer(['code' => 'SHOWME']);

        $response = asCustomerApi($this->accountant, 'GET', customerUri((string) $customer->getKey()));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.id'))->toBe((string) $customer->getKey())
            ->and($response->json('data.code'))->toBe('SHOWME');
    });

    it('updates a customer’s details', function (): void {
        $customer = createCustomer();

        $response = asCustomerApi($this->accountant, 'PUT', customerUri((string) $customer->getKey()), [
            'name' => 'Silva Traders (Pvt) Ltd',
            'payment_terms_days' => 45,
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.name'))->toBe('Silva Traders (Pvt) Ltd')
            ->and($response->json('data.payment_terms_days'))->toBe(45);
    });

    it('refuses a code already used by another customer on update', function (): void {
        createCustomer(['code' => 'TAKEN']);
        $second = createCustomer(['code' => 'FREE']);

        $response = asCustomerApi($this->accountant, 'PUT', customerUri((string) $second->getKey()), ['code' => 'taken']);

        expect($response)->toBeProblem('duplicate-resource', 409);
    });

    it('refuses to change the code once the customer has been invoiced', function (): void {
        $customer = createCustomer(['code' => 'OLD']);
        withCustomerReceivables('0.0000', hasInvoice: true);

        $response = asCustomerApi($this->accountant, 'PUT', customerUri((string) $customer->getKey()), ['code' => 'NEW']);

        expect($response)->toBeProblem('customer-code-locked', 422);
    });
});

describe('the lifecycle actions', function (): void {
    it('archives a customer', function (): void {
        $customer = createCustomer();

        $response = asCustomerApi($this->accountant, 'POST', customerUri($customer->getKey().'/archive'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.status'))->toBe('archived');
    });

    it('refuses to archive a customer with an outstanding balance', function (): void {
        $customer = createCustomer();
        withCustomerReceivables('15000.0000');

        $response = asCustomerApi($this->accountant, 'POST', customerUri($customer->getKey().'/archive'));

        expect($response)->toBeProblem('customer-has-outstanding-balance', 422);
    });

    it('reactivates an archived customer', function (): void {
        $customer = createCustomer();
        app(CustomerService::class)->archive($customer);

        $response = asCustomerApi($this->accountant, 'POST', customerUri($customer->getKey().'/reactivate'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.status'))->toBe('active');
    });

    it('deactivates a customer without hiding it', function (): void {
        $customer = createCustomer();

        $response = asCustomerApi($this->accountant, 'POST', customerUri($customer->getKey().'/deactivate'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.status'))->toBe('inactive');
    });

    it('soft-deletes a customer, then restores it', function (): void {
        $id = (string) createCustomer(['code' => 'ROUNDTRIP'])->getKey();

        expect(asCustomerApi($this->accountant, 'DELETE', customerUri($id))->getStatusCode())->toBe(204);

        expect(asCustomerApi($this->accountant, 'GET', customerUri($id))->getStatusCode())->toBe(404);

        $restoreResponse = asCustomerApi($this->accountant, 'POST', customerUri("{$id}/restore"));
        expect($restoreResponse)->toBeEnvelope()
            ->and($restoreResponse->json('data.id'))->toBe($id);

        expect(asCustomerApi($this->accountant, 'GET', customerUri($id))->getStatusCode())->toBe(200);
    });

    it('refuses to delete a customer that has been invoiced', function (): void {
        $customer = createCustomer();
        withCustomerReceivables('0.0000', hasInvoice: true);

        $response = asCustomerApi($this->accountant, 'DELETE', customerUri((string) $customer->getKey()));

        expect($response)->toBeProblem('customer-has-invoices', 422);
    });

    it('refuses to restore a customer whose code has since been reused', function (): void {
        $original = createCustomer(['code' => 'SILVA']);
        app(CustomerService::class)->delete($original);
        createCustomer(['name' => 'Silva Traders', 'code' => 'SILVA']);

        $response = asCustomerApi($this->accountant, 'POST', customerUri($original->getKey().'/restore'));

        expect($response)->toBeProblem('customer-code-taken-on-restore', 409);
    });

    it('refuses to restore a customer that was never deleted', function (): void {
        $customer = createCustomer();

        $response = asCustomerApi($this->accountant, 'POST', customerUri($customer->getKey().'/restore'));

        expect($response)->toBeProblem('customer-not-deleted', 422);
    });
});

describe('I3 — partial update semantics over HTTP', function (): void {
    it('leaves branch_id, receivable_account_id and credit_limit untouched when a put omits them', function (): void {
        $fixture = customerWithEverythingSet();

        $response = asCustomerApi($this->accountant, 'PUT', customerUri((string) $fixture['customer']->getKey()), [
            'name' => 'Renamed, nothing else sent',
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.branch_id'))->toBe($fixture['branch']->getKey())
            ->and($response->json('data.receivable_account_id'))->toBe($fixture['account']->getKey())
            ->and($response->json('data.credit_limit'))->toBe('25000.0000');
    });

    it('clears branch_id when the put sets it to null, leaving the other two alone', function (): void {
        $fixture = customerWithEverythingSet();

        $response = asCustomerApi($this->accountant, 'PUT', customerUri((string) $fixture['customer']->getKey()), [
            'branch_id' => null,
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.branch_id'))->toBeNull()
            ->and($response->json('data.receivable_account_id'))->toBe($fixture['account']->getKey())
            ->and($response->json('data.credit_limit'))->toBe('25000.0000');
    });

    it('clears receivable_account_id when the put sets it to null, leaving the other two alone', function (): void {
        $fixture = customerWithEverythingSet();

        $response = asCustomerApi($this->accountant, 'PUT', customerUri((string) $fixture['customer']->getKey()), [
            'receivable_account_id' => null,
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.receivable_account_id'))->toBeNull()
            ->and($response->json('data.branch_id'))->toBe($fixture['branch']->getKey())
            ->and($response->json('data.credit_limit'))->toBe('25000.0000');
    });

    it('clears credit_limit when the put sets it to null, leaving the other two alone', function (): void {
        $fixture = customerWithEverythingSet();

        $response = asCustomerApi($this->accountant, 'PUT', customerUri((string) $fixture['customer']->getKey()), [
            'credit_limit' => null,
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.credit_limit'))->toBeNull()
            ->and($response->json('data.branch_id'))->toBe($fixture['branch']->getKey())
            ->and($response->json('data.receivable_account_id'))->toBe($fixture['account']->getKey());
    });
});
