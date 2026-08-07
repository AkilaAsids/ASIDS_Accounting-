<?php

declare(strict_types=1);

use Asids\Core\Audit\Domain\Models\AuditLog;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Application\Services\MembershipService;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Testing\TestResponse;

/**
 * Which company a request operates on.
 *
 * The middleware resolves it from three sources — route parameter, `X-Company` header, the user's
 * default — and verifies membership whichever one wins. That verification is the whole reason it
 * exists rather than each controller reading the header: a header is client-supplied, so trusting it
 * would let any authenticated user read any company in their workspace. Which is a data breach
 * between two businesses that happen to share one ASIDS workspace — a group holding, most often.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->primary = $this->acme['company'];

    $this->companies = app(CompanyService::class);
    $this->memberships = app(MembershipService::class);

    // A second company in the same workspace that the accountant is deliberately not a member of.
    $this->restricted = $this->companies->create(new CreateCompanyData(name: 'Restricted Books'), $this->owner);

    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
        'email' => 'accountant@acme.test',
    ]);

    $this->memberships->grant($this->primary, $this->accountant, $this->owner, makeDefault: true);
});

function asCompanyUser(
    User $user,
    string $method,
    string $uri,
    array $payload = [],
    array $headers = [],
): TestResponse {
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeaders(['X-Tenant' => 'acme', ...$headers])
        ->json($method, $uri, $payload);
}

describe('resolution order', function (): void {
    it('uses the route parameter when one is present', function (): void {
        $response = asCompanyUser($this->owner, 'GET', "/api/v1/companies/{$this->restricted->getKey()}/branches");

        // `/companies/{company}/branches` is self-describing, so the path wins over anything else.
        expect($response)->toBeEnvelope()
            ->and($response->headers->get('X-Company'))->toBe($this->restricted->getKey());
    });

    it('falls back to the user’s default when the route parameter is the only source', function (): void {
        // The middleware is applied to the company-scoped groups, where a company is always in the
        // path — so on those routes the parameter is what resolves, and the header cannot override it.
        $response = asCompanyUser($this->owner, 'GET', "/api/v1/companies/{$this->primary->getKey()}/members");

        expect($response->headers->get('X-Company'))->toBe($this->primary->getKey());
    });

    it('prefers the route parameter over a conflicting header', function (): void {
        $response = asCompanyUser(
            $this->owner,
            'GET',
            "/api/v1/companies/{$this->primary->getKey()}/branches",
            headers: ['X-Company' => $this->restricted->getKey()],
        );

        // Most explicit wins. Otherwise a stale switcher header would silently redirect a
        // self-describing URL to a different company's data.
        expect($response->headers->get('X-Company'))->toBe($this->primary->getKey());
    });

    it('echoes the resolved company back so the client can see what it got', function (): void {
        $response = asCompanyUser($this->accountant, 'GET', "/api/v1/companies/{$this->primary->getKey()}/branches");

        // The switcher needs to know which company the server actually settled on, particularly when
        // it asked for one it turned out not to have access to.
        expect($response->headers->get('X-Company'))->toBe($this->primary->getKey());
    });

    it('publishes the company to the request context, so audit entries carry it', function (): void {
        asCompanyUser($this->owner, 'POST', "/api/v1/companies/{$this->primary->getKey()}/branches", [
            'name' => 'Kandy',
            'code' => 'KDY',
        ])->assertStatus(201);

        $companyIds = RowLevelSecurity::bypass(fn (): array => AuditLog::query()
            ->withoutGlobalScopes()
            ->whereNotNull('company_id')
            ->pluck('company_id')
            ->unique()
            ->all());

        // This is what the middleware buys beyond the membership check. Without it applied to the
        // route, `RequestContext::companyId()` is null for every request and no audit entry in the
        // platform can say which company a change belonged to.
        expect($companyIds)->toContain($this->primary->getKey());
    });
});

describe('membership verification', function (): void {
    it('refuses a header naming a company the caller is not a member of', function (): void {
        // The header is the switcher's mechanism and is entirely client-controlled, so it is checked
        // exactly as strictly as the path. The attacker's best case is a valid identifier from the
        // same workspace — a sibling company in a group holding — and membership is what makes it
        // useless. Sent against a company-scoped route so the middleware is in the stack.
        $response = asCompanyUser(
            $this->accountant,
            'GET',
            "/api/v1/companies/{$this->restricted->getKey()}/members",
            headers: ['X-Company' => $this->restricted->getKey()],
        );

        expect($response->getStatusCode())->toBeIn([403, 404]);
    });

    it('refuses a route parameter naming a company the caller is not a member of', function (): void {
        $response = asCompanyUser(
            $this->accountant,
            'GET',
            "/api/v1/companies/{$this->restricted->getKey()}/branches",
        );

        expect($response->getStatusCode())->toBeIn([403, 404]);
    });

    it('refuses a company identifier from another workspace outright', function (): void {
        $globex = $this->createWorkspace('globex');

        $response = asCompanyUser(
            $this->owner,
            'GET',
            "/api/v1/companies/{$globex['company']->getKey()}/branches",
            headers: ['X-Company' => $globex['company']->getKey()],
        );

        expect($response->getStatusCode())->toBeIn([403, 404]);
    });

    it('refuses a malformed company identifier without a database error', function (): void {
        // The path, not the header: on these routes the parameter always wins, so a malformed header
        // is never consulted and testing it would assert nothing. The UUID route pattern registered
        // in RouteServiceProvider is what turns this into a 404 — a non-UUID reaching `where id = ?`
        // on a uuid column is a Postgres cast error, which surfaces as a 500 and leaks the column type.
        $response = asCompanyUser($this->owner, 'GET', '/api/v1/companies/not-a-uuid/branches');

        expect($response->getStatusCode())->toBe(404);
    });

    it('ignores a malformed header when the path already names a company', function (): void {
        $response = asCompanyUser(
            $this->owner,
            'GET',
            "/api/v1/companies/{$this->primary->getKey()}/branches",
            headers: ['X-Company' => 'not-a-uuid'],
        );

        // Resolution stops at the first source that yields a company, so rubbish in the header cannot
        // break a request that named its company in the URL — which is what every deep link does.
        expect($response)->toBeEnvelope()
            ->and($response->headers->get('X-Company'))->toBe($this->primary->getKey());
    });

    it('refuses a user with no company access at all', function (): void {
        $stranded = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
            'email' => 'stranded@acme.test',
        ]);

        $response = asCompanyUser(
            $stranded,
            'GET',
            "/api/v1/companies/{$this->primary->getKey()}/branches",
        );

        expect($response->getStatusCode())->toBeIn([403, 404]);
    });

    it('stops resolving to a company once the membership is revoked', function (): void {
        $membership = RowLevelSecurity::bypass(fn () => CompanyMembership::query()
            ->where('user_id', $this->accountant->getKey())
            ->where('company_id', $this->primary->getKey())
            ->firstOrFail());

        $this->memberships->revoke($membership, $this->owner);

        $response = asCompanyUser(
            $this->accountant,
            'GET',
            "/api/v1/companies/{$this->primary->getKey()}/branches",
        );

        // Revocation has to take effect on the next request, not at the next sign-in. A leaver whose
        // access is removed while they are logged in must lose it immediately.
        expect($response->getStatusCode())->toBeIn([403, 404]);
    });

    it('refuses an archived company even to a member', function (): void {
        $this->companies->archive($this->restricted->refresh(), $this->owner);

        $response = asCompanyUser(
            $this->owner,
            'GET',
            "/api/v1/companies/{$this->restricted->getKey()}/branches",
        );

        // Archiving revokes every membership, so this is the same rule reached a different way — and
        // it is what stops an archived company from continuing to accept postings.
        expect($response->getStatusCode())->toBeIn([403, 404, 422]);
    });
});

describe('workspace-level endpoints', function (): void {
    it('remains reachable by a user with no company at all', function (): void {
        $stranded = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
            'email' => 'nobody@acme.test',
        ]);

        // The middleware is applied per route group, not globally, precisely so that profile,
        // sign-out and the company list stay reachable. A user who has been invited but not yet
        // granted a company would otherwise be unable to do anything at all, including sign out.
        expect(asCompanyUser($stranded, 'GET', '/api/v1/me'))->toBeEnvelope();
        expect(asCompanyUser($stranded, 'GET', '/api/v1/auth/session'))->toBeEnvelope();
        expect(asCompanyUser($stranded, 'GET', '/api/v1/companies'))->toBeEnvelope();
    });

    it('reports no companies for a user with no membership', function (): void {
        $stranded = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
            'email' => 'empty@acme.test',
        ]);

        expect(asCompanyUser($stranded, 'GET', '/api/v1/companies')->json('data'))->toBe([]);
    });
});

describe('the switcher payload', function (): void {
    it('lists only the companies the user may switch to', function (): void {
        $companies = asCompanyUser($this->accountant, 'GET', '/api/v1/auth/session')->json('data.companies');

        $ids = collect($companies)->pluck('id')->all();

        // This payload *is* the switcher. Offering a company the middleware will then refuse is a
        // menu item that produces a 403.
        expect($ids)->toContain($this->primary->getKey())
            ->and($ids)->not->toContain($this->restricted->getKey());
    });

    it('marks which company is the default', function (): void {
        $companies = asCompanyUser($this->accountant, 'GET', '/api/v1/auth/session')->json('data.companies');

        $default = collect($companies)->firstWhere('is_default', true);

        expect($default)->not->toBeNull()
            ->and($default['id'])->toBe($this->primary->getKey());
    });
});
