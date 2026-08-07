<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Testing\TestResponse;

/**
 * The companies, branches and memberships HTTP surface.
 *
 * CompanyLifecycleTest and BranchAndMembershipTest cover the services' invariants. This covers what
 * only the edge can show: that the list is scoped by *membership* and not merely by workspace, that
 * nested routes verify the parent actually owns the child, and that the resources carry the fiscal
 * arithmetic the SPA is deliberately not allowed to reimplement.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];
    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
        'email' => 'accountant@acme.test',
    ]);
});

function asOrg(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

describe('listing companies', function (): void {
    it('returns the companies the caller is a member of', function (): void {
        $response = asOrg($this->owner, 'GET', '/api/v1/companies');

        expect($response)->toBeEnvelope()
            ->and(collect($response->json('data'))->pluck('id')->all())->toContain($this->company->getKey());
    });

    it('does not list a company the caller has no membership of', function (): void {
        $other = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Owner Only'),
            $this->owner,
        );

        $ids = collect(asOrg($this->accountant, 'GET', '/api/v1/companies')->json('data'))->pluck('id')->all();

        // Membership is data access, separate from and additional to permissions: the accountant has
        // `organization.companies.view` and still must not see a company they do not belong to.
        expect($ids)->not->toContain($other->getKey());
    });

    it('resolves the fiscal year server-side', function (): void {
        $response = asOrg($this->owner, 'GET', '/api/v1/companies');

        $first = collect($response->json('data'))->firstWhere('id', $this->company->getKey());

        // Sent resolved so the SPA never reimplements the fiscal calendar — the single most
        // error-prone calculation to duplicate, and the one where a mismatch silently puts a report
        // in the wrong year.
        expect($first['accounting']['current_fiscal_year'])->toHaveKeys(['starts_on', 'ends_on']);
    });

    it('cannot read a company in another workspace', function (): void {
        $response = asOrg($this->owner, 'GET', "/api/v1/companies/{$this->globex['company']->getKey()}");

        expect($response->getStatusCode())->toBe(404);
    });
});

describe('creating and updating a company', function (): void {
    it('creates a company', function (): void {
        $response = asOrg($this->owner, 'POST', '/api/v1/companies', [
            'name' => 'Second Entity',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
        ]);

        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.name'))->toBe('Second Entity');
    });

    it('refuses a fiscal start day the calendar cannot honour', function (): void {
        $response = asOrg($this->owner, 'POST', '/api/v1/companies', [
            'name' => 'Bad Calendar',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
            'fiscal_year_start_month' => 2,
            'fiscal_year_start_day' => 30,
        ]);

        // Capped at 28 to match the database constraint: a fiscal year starting on the 29th is
        // undefined in February, and three years in four it would silently shift.
        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('fiscal_year_start_day');
    });

    it('refuses an unknown timezone', function (): void {
        $response = asOrg($this->owner, 'POST', '/api/v1/companies', [
            'name' => 'Nowhere',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Mars/Olympus',
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('updates a company’s contact details', function (): void {
        $response = asOrg($this->owner, 'PUT', "/api/v1/companies/{$this->company->getKey()}", [
            'name' => $this->company->name,
            'city' => 'Kandy',
            'phone' => '+94112345678',
        ]);

        expect($response)->toBeEnvelope()
            ->and($this->company->refresh()->city)->toBe('Kandy');
    });

    it('refuses a caller without the update permission', function (): void {
        $viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer');

        $response = asOrg($viewer, 'PUT', "/api/v1/companies/{$this->company->getKey()}", [
            'name' => 'Renamed',
        ]);

        expect($response->getStatusCode())->toBe(403);
    });
});

describe('company lifecycle endpoints', function (): void {
    it('archives and restores a company', function (): void {
        $second = asOrg($this->owner, 'POST', '/api/v1/companies', [
            'name' => 'Closing Down',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
        ])->json('data.id');

        expect(asOrg($this->owner, 'POST', "/api/v1/companies/{$second}/archive"))->toBeEnvelope();
        expect(asOrg($this->owner, 'POST', "/api/v1/companies/{$second}/restore"))->toBeEnvelope();
    });

    it('refuses to archive the workspace default through the endpoint', function (): void {
        $response = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/archive");

        expect($response)->toBeProblem('cannot-archive-default-company');
    });

    it('moves the workspace default', function (): void {
        $second = asOrg($this->owner, 'POST', '/api/v1/companies', [
            'name' => 'New Default',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
        ])->json('data.id');

        expect(asOrg($this->owner, 'POST', "/api/v1/companies/{$second}/make-default"))->toBeEnvelope();

        expect(RowLevelSecurity::bypass(fn (): int => Company::query()->where('is_default', true)->count()))
            ->toBe(1);
    });
});

describe('branches', function (): void {
    it('lists a company’s branches', function (): void {
        $response = asOrg($this->owner, 'GET', "/api/v1/companies/{$this->company->getKey()}/branches");

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->not->toBeEmpty();
    });

    it('creates a branch', function (): void {
        $response = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/branches", [
            'name' => 'Kandy',
            'code' => 'KDY',
        ]);

        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.is_primary'))->toBeFalse();
    });

    it('refuses a duplicate branch code', function (): void {
        asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/branches", [
            'name' => 'Kandy', 'code' => 'KDY',
        ]);

        $response = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/branches", [
            'name' => 'Kandy Two', 'code' => 'KDY',
        ]);

        expect($response)->toBeProblem('duplicate-resource');
    });

    it('moves the primary designation', function (): void {
        $branch = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/branches", [
            'name' => 'Kandy', 'code' => 'KDY',
        ])->json('data.id');

        expect(asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/branches/{$branch}/make-primary"))
            ->toBeEnvelope();

        expect(RowLevelSecurity::bypass(fn (): int => Branch::query()
            ->forCompany($this->company->getKey())
            ->where('is_primary', true)
            ->count()))->toBe(1);
    });

    it('refuses a branch that belongs to a different company in the path', function (): void {
        $other = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Elsewhere'),
            $this->owner,
        );

        $foreignBranch = RowLevelSecurity::bypass(
            fn (): Branch => Branch::query()->forCompany($other->getKey())->firstOrFail(),
        );

        $response = asOrg(
            $this->owner,
            'GET',
            "/api/v1/companies/{$this->company->getKey()}/branches/{$foreignBranch->getKey()}",
        );

        // Nested route bindings resolve independently, so the controller checks the relationship
        // itself rather than relying on scoped bindings being registered. Without it,
        // `/companies/{mine}/branches/{yours}` reads a branch through a company the caller does have
        // access to.
        expect($response)->toBeProblem('branch-company-mismatch');
    });

    it('archives a non-primary branch and refuses the primary one', function (): void {
        $branch = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/branches", [
            'name' => 'Kandy', 'code' => 'KDY',
        ])->json('data.id');

        expect(asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/branches/{$branch}/archive"))
            ->toBeEnvelope();

        $primary = RowLevelSecurity::bypass(fn (): Branch => Branch::query()
            ->forCompany($this->company->getKey())
            ->where('is_primary', true)
            ->firstOrFail());

        $response = asOrg(
            $this->owner,
            'POST',
            "/api/v1/companies/{$this->company->getKey()}/branches/{$primary->getKey()}/archive",
        );

        expect($response)->toBeProblem('cannot-archive-primary-branch');
    });
});

describe('company memberships', function (): void {
    it('lists a company’s members', function (): void {
        $response = asOrg($this->owner, 'GET', "/api/v1/companies/{$this->company->getKey()}/members");

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->not->toBeEmpty();
    });

    it('grants access to a user', function (): void {
        $response = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/members", [
            'user_id' => $this->accountant->getKey(),
        ]);

        expect($response->getStatusCode())->toBe(201);
    });

    it('refuses a user from another workspace', function (): void {
        // `exists:users,id` runs against the tenant-scoped table, so a foreign id fails validation
        // rather than reaching the service — and the message does not confirm the user exists.
        $response = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/members", [
            'user_id' => $this->globex['owner']->getKey(),
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('revokes access', function (): void {
        $membership = asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/members", [
            'user_id' => $this->accountant->getKey(),
        ])->json('data.id');

        $response = asOrg($this->owner, 'DELETE', "/api/v1/companies/{$this->company->getKey()}/members/{$membership}");

        expect($response->getStatusCode())->toBeIn([200, 204]);
    });

    it('lets a member choose the company they land in', function (): void {
        asOrg($this->owner, 'POST', "/api/v1/companies/{$this->company->getKey()}/members", [
            'user_id' => $this->accountant->getKey(),
        ]);

        $response = asOrg($this->accountant, 'POST', "/api/v1/companies/{$this->company->getKey()}/select");

        expect($response)->toBeEnvelope()
            ->and($this->accountant->refresh()->default_company_id)->toBe($this->company->getKey());
    });

    it('refuses to select a company the caller cannot reach', function (): void {
        $other = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Not Mine'),
            $this->owner,
        );

        $response = asOrg($this->accountant, 'POST', "/api/v1/companies/{$other->getKey()}/select");

        expect($response->getStatusCode())->toBeIn([403, 404, 422]);
    });
});
