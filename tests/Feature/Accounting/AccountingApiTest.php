<?php

declare(strict_types=1);

use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Authorization\Application\DTOs\RoleData;
use Asids\Core\Authorization\Application\Services\RoleService;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * The accounting HTTP surface, and the Phase 1 seams it finally closes.
 *
 * Two things are being tested that the service-level suites cannot reach. The first is the
 * bookkeeper/accountant split as a client experiences it: a bookkeeper drafts and is refused when
 * they try to post, which is a policy question rather than a service one. The second is that
 * `LedgerActivityProbe` is now answered for real — Phase 1 wrote the rule that a company's base
 * currency becomes immutable once its books have activity, and until this phase there was no activity
 * it could ever see.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'acct@acme.test']);
    $this->bookkeeper = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', ['email' => 'book@acme.test']);
    $this->viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer', ['email' => 'view@acme.test']);

    // Both need company access: permission and membership are different questions and the policies
    // ask both.
    $memberships = app(MembershipService::class);
    foreach ([$this->accountant, $this->bookkeeper, $this->viewer] as $member) {
        $memberships->grant($this->company, $member, $this->owner);
    }

    app(ChartTemplateService::class)->apply($this->company);
    app(FiscalCalendarService::class)->openYearContaining($this->company, CarbonImmutable::parse('2026-06-15'));

    $this->cash = byCode('1110');
    $this->sales = byCode('4100');
});

function byCode(string $code): Account
{
    return Account::query()->forCompany(test()->company->getKey())->where('code', $code)->firstOrFail();
}

function asAccounting(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $fresh = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($fresh ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->withHeader('X-Company', test()->company->getKey())
        ->json($method, $uri, $payload);
}

function companyUri(string $suffix): string
{
    return '/api/v1/companies/'.test()->company->getKey().'/'.ltrim($suffix, '/');
}

/**
 * @return array<string, mixed>
 */
function balancedPayload(string $amount = '1000.00', bool $post = false): array
{
    return [
        'entry_date' => '2026-06-15',
        'description' => 'Cash sale',
        'lines' => [
            ['account_id' => test()->cash->getKey(), 'debit' => $amount],
            ['account_id' => test()->sales->getKey(), 'credit' => $amount],
        ],
        'post' => $post,
    ];
}

/**
 * The same entry with the credit side short, for the paths that must refuse it.
 *
 * @return array<string, mixed>
 */
function unbalancedPayload(bool $post = false): array
{
    return [...balancedPayload(post: $post), 'lines' => [
        ['account_id' => test()->cash->getKey(), 'debit' => '1000.00'],
        ['account_id' => test()->sales->getKey(), 'credit' => '940.00'],
    ]];
}

describe('the chart of accounts endpoint', function (): void {
    it('lists the chart', function (): void {
        $response = asAccounting($this->accountant, 'GET', companyUri('accounts'));

        expect($response)->toBeEnvelope()
            ->and(collect($response->json('data'))->pluck('code')->all())->toContain('1110', '4100');
    });

    it('reports the derived normal balance rather than making the client infer it', function (): void {
        $response = asAccounting($this->accountant, 'GET', companyUri('accounts'));

        $cash = collect($response->json('data'))->firstWhere('code', '1110');

        // A front end that reimplemented the type-to-sign mapping would be a second place for it to
        // be wrong, and the symptom is a report with every figure inverted.
        expect($cash['normal_balance'])->toBe('debit')
            ->and($cash['statement'])->toBe('balance_sheet');
    });

    it('creates an account', function (): void {
        $response = asAccounting($this->accountant, 'POST', companyUri('accounts'), [
            'code' => '1180',
            'name' => 'Petty Cash',
            'type' => 'asset',
        ]);

        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.normal_balance'))->toBe('debit');
    });

    it('refuses a bookkeeper the right to change the chart', function (): void {
        $response = asAccounting($this->bookkeeper, 'POST', companyUri('accounts'), [
            'code' => '1180', 'name' => 'Petty Cash', 'type' => 'asset',
        ]);

        expect($response->getStatusCode())->toBe(403);
    });

    it('refuses an invented account type at the boundary', function (): void {
        $response = asAccounting($this->accountant, 'POST', companyUri('accounts'), [
            'code' => '9999', 'name' => 'Nonsense', 'type' => 'liquid',
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('does not accept a normal balance from the client', function (): void {
        asAccounting($this->accountant, 'POST', companyUri('accounts'), [
            'code' => '1180',
            'name' => 'Petty Cash',
            'type' => 'asset',
            // Ignored rather than honoured. Accepting it would create an account that reports every
            // figure backwards while the books still balance.
            'normal_balance' => 'credit',
        ]);

        expect(byCode('1180')->normal_balance->value)->toBe('debit');
    });

    it('offers the starter template with its disclaimer', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(MembershipService::class)
            ->grant($second, $this->accountant, $this->owner);

        $response = test()->actingAs(RowLevelSecurity::bypass(fn () => $this->accountant->fresh()))
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $second->getKey())
            ->getJson('/api/v1/companies/'.$second->getKey().'/accounts/template');

        // The disclaimer travels in the payload. A caveat that lives only in documentation is one
        // nobody clicking "apply" reads.
        expect($response->json('data.disclaimer'))->toContain('not professional or statutory advice')
            ->and($response->json('data.version'))->toBe(ChartTemplate::VERSION)
            ->and($response->json('data.can_apply'))->toBeTrue();
    });

    it('repeats the disclaimer when the template is applied', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(MembershipService::class)
            ->grant($second, $this->accountant, $this->owner);

        $response = test()->actingAs(RowLevelSecurity::bypass(fn () => $this->accountant->fresh()))
            ->withHeader('X-Tenant', 'acme')
            ->withHeader('X-Company', $second->getKey())
            ->postJson('/api/v1/companies/'.$second->getKey().'/accounts/template');

        expect($response->json('meta.disclaimer'))->toContain('qualified Sri Lankan accountant');
    });

    it('never shows another workspace’s accounts', function (): void {
        $response = asAccounting($this->accountant, 'GET', companyUri('accounts'));

        $foreign = RowLevelSecurity::bypass(fn (): array => Account::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->globex['tenant']->getKey())
            ->pluck('id')
            ->all());

        expect(array_intersect(collect($response->json('data'))->pluck('id')->all(), $foreign))->toBe([]);
    });
});

describe('the drafting and posting split', function (): void {
    it('lets a bookkeeper draft', function (): void {
        $response = asAccounting($this->bookkeeper, 'POST', companyUri('journal-entries'), balancedPayload());

        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.status'))->toBe('draft')
            ->and($response->json('data.number'))->toBeNull();
    });

    it('refuses a bookkeeper the right to post', function (): void {
        $response = asAccounting($this->bookkeeper, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        // The whole reason drafting and posting are separate capabilities. A bookkeeper records what
        // happened; an accountant decides it is part of the record.
        expect($response->getStatusCode())->toBe(403);
    });

    it('tells a bookkeeper what they may do with their own draft', function (): void {
        $response = asAccounting($this->bookkeeper, 'POST', companyUri('journal-entries'), balancedPayload());

        // The client renders its buttons from this rather than guessing from the role name.
        expect($response->json('data.capabilities.can_update'))->toBeTrue()
            ->and($response->json('data.capabilities.can_post'))->toBeFalse();
    });

    it('leaves nothing behind when a post is refused for not balancing', function (): void {
        $before = JournalEntry::query()->forCompany((string) $this->company->getKey())->count();

        $response = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), unbalancedPayload(post: true));

        // Draft-then-post is one call, so it must be one transaction. Committed separately, an
        // accountant who mistypes an amount is told the entry does not balance *and* silently gains a
        // draft in the books for every attempt — the books collect the typos.
        expect($response->getStatusCode())->toBe(422)
            ->and(JournalEntry::query()->forCompany((string) $this->company->getKey())->count())->toBe($before);
    });

    it('leaves nothing behind when the caller may draft but not post', function (): void {
        $before = JournalEntry::query()->forCompany((string) $this->company->getKey())->count();

        $response = asAccounting($this->bookkeeper, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        // The same rule for the authorisation refusal. A bookkeeper asking for something they may not
        // have should get a 403 and no trace, not a 403 and a draft they never meant to leave.
        expect($response->getStatusCode())->toBe(403)
            ->and(JournalEntry::query()->forCompany((string) $this->company->getKey())->count())->toBe($before);
    });

    it('does not offer to post an entry that is already posted, even to the owner', function (): void {
        $response = asAccounting($this->owner, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        // A tenant owner is short-circuited through `Gate::before`, so the status guards inside
        // `JournalEntryPolicy` never run for them. Without the entry's own state being consulted, the
        // API reports that an owner may post — and update — an entry that is already in the ledger,
        // and a client built from that offers buttons whose only outcome is a 422.
        expect($response->json('data.status'))->toBe('posted')
            ->and($response->json('data.capabilities.can_post'))->toBeFalse()
            ->and($response->json('data.capabilities.can_update'))->toBeFalse()
            ->and($response->json('data.capabilities.can_reverse'))->toBeTrue();
    });

    it('offers the owner both actions while the entry is still a draft', function (): void {
        // The other side of the rule above: the state check must not swallow what an owner may
        // genuinely do, or the buttons disappear from the one status that needs them.
        $response = asAccounting($this->owner, 'POST', companyUri('journal-entries'), balancedPayload());

        expect($response->json('data.status'))->toBe('draft')
            ->and($response->json('data.capabilities.can_post'))->toBeTrue()
            ->and($response->json('data.capabilities.can_update'))->toBeTrue()
            ->and($response->json('data.capabilities.can_reverse'))->toBeFalse();
    });

    it('lets an accountant draft and post in one call', function (): void {
        $response = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.status'))->toBe('posted')
            ->and($response->json('data.number'))->toBe('JV-2026-06-0001');
    });

    it('posts a bookkeeper’s draft when an accountant approves it', function (): void {
        $draftId = asAccounting($this->bookkeeper, 'POST', companyUri('journal-entries'), balancedPayload())
            ->json('data.id');

        $response = asAccounting($this->accountant, 'POST', companyUri("journal-entries/{$draftId}/post"));

        // The workflow the split exists for, end to end.
        expect($response)->toBeEnvelope()
            ->and($response->json('data.status'))->toBe('posted');
    });

    it('refuses to post an unbalanced entry and names the difference', function (): void {
        $response = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), [
            'entry_date' => '2026-06-15',
            'description' => 'Unbalanced',
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => '100.00'],
                ['account_id' => $this->sales->getKey(), 'credit' => '60.00'],
            ],
            'post' => true,
        ]);

        expect($response)->toBeProblem('unbalanced-entry')
            ->and($response->json('detail'))->toContain('40.0000');
    });

    it('rejects an amount with more than four decimal places', function (): void {
        $response = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), [
            'entry_date' => '2026-06-15',
            'description' => 'Too precise',
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => '100.000001'],
                ['account_id' => $this->sales->getKey(), 'credit' => '100.000001'],
            ],
        ]);

        // Rejected rather than rounded. Silently dropping a digit from a submitted amount is how a
        // total stops matching the document it came from.
        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('emits amounts as strings, never as JSON numbers', function (): void {
        asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload('1234.56', post: true));

        $entry = JournalEntry::query()->forCompany($this->company->getKey())->posted()->firstOrFail();

        $response = asAccounting($this->accountant, 'GET', companyUri("journal-entries/{$entry->getKey()}"));

        // A JSON number is an IEEE-754 double in most clients, and a monetary amount that round-trips
        // through one is no longer the amount that was stored.
        expect($response->json('data.lines.0.debit'))->toBeString()->toBe('1234.5600');
    });

    it('refuses to edit a posted entry', function (): void {
        $id = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true))
            ->json('data.id');

        $response = asAccounting($this->accountant, 'PUT', companyUri("journal-entries/{$id}"), balancedPayload('50.00'));

        expect($response->getStatusCode())->toBe(403);
    });

    it('reverses a posted entry with a reason', function (): void {
        $id = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true))
            ->json('data.id');

        $response = asAccounting($this->accountant, 'POST', companyUri("journal-entries/{$id}/reverse"), [
            'reason' => 'Posted to the wrong account',
        ]);

        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.reverses_entry_id'))->toBe($id);
    });

    it('requires a reason to reverse', function (): void {
        $id = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true))
            ->json('data.id');

        $response = asAccounting($this->accountant, 'POST', companyUri("journal-entries/{$id}/reverse"));

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses a viewer any write at all', function (): void {
        expect(asAccounting($this->viewer, 'POST', companyUri('journal-entries'), balancedPayload())->getStatusCode())
            ->toBe(403);
    });

    it('lets a viewer read', function (): void {
        asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        expect(asAccounting($this->viewer, 'GET', companyUri('journal-entries'))->getStatusCode())->toBe(200);
    });
});

describe('the reports', function (): void {
    beforeEach(function (): void {
        asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload('1000.00', post: true));
    });

    it('returns a trial balance that states whether it ties', function (): void {
        $response = asAccounting($this->accountant, 'GET', companyUri('reports/trial-balance').'?from=2026-06-01&to=2026-06-30');

        // Stated explicitly rather than left for the client to work out by comparing two strings. If
        // it is ever false the client should say so loudly rather than rendering an ordinary report.
        expect($response->json('meta.ties'))->toBeTrue()
            ->and($response->json('meta.totals.debit'))->toBe('1000.0000')
            ->and($response->json('meta.totals.credit'))->toBe('1000.0000');
    });

    it('returns an account ledger with opening and closing balances', function (): void {
        $response = asAccounting(
            $this->accountant,
            'GET',
            companyUri('accounts/'.$this->cash->getKey().'/ledger').'?from=2026-06-01&to=2026-06-30',
        );

        expect($response->json('meta.opening_balance'))->toBe('0.0000')
            ->and($response->json('meta.closing_balance'))->toBe('1000.0000')
            ->and($response->json('data'))->toHaveCount(1);
    });

    it('defaults the range to the company’s fiscal year, not the calendar year', function (): void {
        $response = asAccounting($this->accountant, 'GET', companyUri('reports/trial-balance'));

        // A company with an April year start asking for "this year" means their year. A January
        // default would silently report the wrong nine months.
        expect($response->json('meta.from'))->toBe(
            $this->company->fiscalYearStartFor(CarbonImmutable::now())->toDateString(),
        );
    });

    it('refuses a caller whose role omits the reports permission', function (): void {
        // A custom role rather than a built-in one, because every built-in role that can reach these
        // endpoints legitimately has `accounting.reports.view` — the administrator template is
        // defined as *every* tenant-grantable capability, so it gained the accounting ones the moment
        // they were added to the catalogue. Testing against a purpose-built role is what isolates the
        // permission rather than the role.
        $role = app(RoleService::class)->create(
            new RoleData(
                label: 'Ledger Reader',
                description: 'Reads entries but not reports.',
                permissionNames: [
                    'organization.companies.view',
                    'accounting.accounts.view',
                    'accounting.journals.view',
                ],
            ),
            $this->owner,
        );

        $reader = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper', ['email' => 'reader@acme.test']);
        app(MembershipService::class)
            ->grant($this->company, $reader, $this->owner);

        app(RoleService::class)
            ->assign($reader, [$role->getKey()], $this->owner);

        expect(asAccounting($reader, 'GET', companyUri('reports/trial-balance'))->getStatusCode())->toBe(403);
    });
});

describe('the fiscal calendar endpoint', function (): void {
    it('lists years with their periods', function (): void {
        $response = asAccounting($this->accountant, 'GET', companyUri('fiscal-calendar'));

        expect($response)->toBeEnvelope()
            ->and($response->json('data.0.periods'))->toHaveCount(12);
    });

    it('closes and reopens a period', function (): void {
        $periods = collect(asAccounting($this->accountant, 'GET', companyUri('fiscal-calendar'))->json('data.0.periods'));

        $january = $periods->firstWhere('sequence', 1);

        $closed = asAccounting($this->accountant, 'POST', companyUri("fiscal-calendar/periods/{$january['id']}/close"));
        expect($closed->json('data.status'))->toBe('closed');

        $reopened = asAccounting($this->owner, 'POST', companyUri("fiscal-calendar/periods/{$january['id']}/reopen"), [
            'reason' => 'Supplier invoice arrived late',
        ]);

        expect($reopened->json('data.status'))->toBe('open')
            ->and($reopened->json('data.reopen_reason'))->toBe('Supplier invoice arrived late');
    });

    it('refuses an accountant the right to reopen', function (): void {
        $periods = collect(asAccounting($this->accountant, 'GET', companyUri('fiscal-calendar'))->json('data.0.periods'));
        $january = $periods->firstWhere('sequence', 1);

        asAccounting($this->accountant, 'POST', companyUri("fiscal-calendar/periods/{$january['id']}/close"));

        // Closing is routine month-end work; reopening changes figures that may already have been
        // filed, and whoever signed those off should be the one deciding to move them.
        expect(asAccounting($this->accountant, 'POST', companyUri("fiscal-calendar/periods/{$january['id']}/reopen"), [
            'reason' => 'Changed my mind',
        ])->getStatusCode())->toBe(403);
    });

    it('reports the year result before closing', function (): void {
        asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload('5000.00', post: true));

        $yearId = asAccounting($this->accountant, 'GET', companyUri('fiscal-calendar'))->json('data.0.id');

        $response = asAccounting($this->accountant, 'GET', companyUri("fiscal-calendar/years/{$yearId}/result"));

        expect($response->json('data.net_result'))->toBe('5000.0000');
    });
});

describe('the Phase 1 seams, now answered for real', function (): void {
    it('lets a company change its base currency while the books are empty', function (): void {
        $response = asAccounting($this->accountant, 'PUT', '/api/v1/companies/'.$this->company->getKey(), [
            'base_currency_code' => 'USD',
        ]);

        expect($response->getStatusCode())->toBe(200);
    });

    it('locks the base currency once the ledger has activity', function (): void {
        asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        $response = asAccounting($this->accountant, 'PUT', '/api/v1/companies/'.$this->company->getKey(), [
            'base_currency_code' => 'USD',
        ]);

        // Phase 1 wrote this rule and bound `NoLedgerActivity`, because no postable table existed.
        // This is the first time it has ever fired: changing the currency does not convert anything,
        // it silently reinterprets every historical amount.
        expect($response)->toBeProblem('base-currency-locked');
    });

    it('locks the fiscal calendar once the ledger has activity', function (): void {
        asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        $response = asAccounting($this->accountant, 'PUT', '/api/v1/companies/'.$this->company->getKey(), [
            'fiscal_year_start_month' => 4,
        ]);

        expect($response)->toBeProblem('fiscal-calendar-locked');
    });

    it('does not count an unposted draft as activity', function (): void {
        asAccounting($this->bookkeeper, 'POST', companyUri('journal-entries'), balancedPayload());

        // A draft can be edited or discarded, so a company with nothing but drafts genuinely can
        // still correct its currency. Telling a customer on their first afternoon that a typo is
        // permanent because of an entry they have not committed would be wrong.
        expect(asAccounting($this->accountant, 'PUT', '/api/v1/companies/'.$this->company->getKey(), [
            'base_currency_code' => 'USD',
        ])->getStatusCode())->toBe(200);
    });

    it('records a posted entry in the audit trail', function (): void {
        asAccounting($this->accountant, 'POST', companyUri('journal-entries'), balancedPayload(post: true));

        // JournalEntry is the Auditable trait's first production consumer. Phase 1 shipped that trait
        // with nothing applying it, because the business documents it was written for did not exist.
        expect(AuditLog::query()
            ->where('auditable_type', 'journal_entry')
            ->exists())->toBeTrue();
    });
});

describe('cross-company isolation', function (): void {
    it('refuses an entry naming an account from another company', function (): void {
        $second = app(CompanyService::class)->create(new CreateCompanyData(name: 'Second Books'), $this->owner);
        app(ChartTemplateService::class)->apply($second);

        $foreign = Account::query()->forCompany($second->getKey())->where('code', '1110')->firstOrFail();

        $response = asAccounting($this->accountant, 'POST', companyUri('journal-entries'), [
            'entry_date' => '2026-06-15',
            'description' => 'Cross company',
            'lines' => [
                ['account_id' => $foreign->getKey(), 'debit' => '100.00'],
                ['account_id' => $this->sales->getKey(), 'credit' => '100.00'],
            ],
        ]);

        // A workspace holding several legal entities is the normal case, and one entity's figures
        // reaching another's books is the failure that matters most in it.
        expect($response)->toBeProblem('account-foreign-company');
    });

    it('refuses a caller with no membership of the company', function (): void {
        $stranger = $this->createUserWithRole($this->acme['tenant'], 'accountant', ['email' => 'stranger@acme.test']);

        // Permission and membership are different questions. This user has every accounting
        // capability and no business in this company's books.
        //
        // 404 rather than 403, and that is the better answer: telling a non-member that the company
        // exists but is forbidden confirms which companies a workspace contains. The middleware says
        // "does not exist, or you do not have access to it" and means both.
        $response = asAccounting($stranger, 'GET', companyUri('accounts'));

        expect($response->getStatusCode())->toBe(404)
            ->and($response)->toBeProblem('company-not-available');
    });
});
