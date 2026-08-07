<?php

declare(strict_types=1);

use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Enums\TenantStatus;
use Asids\Core\Tenancy\Domain\Models\Domain;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Public workspace sign-up.
 *
 * The one endpoint with no authenticated caller, and the one that has to get five things right in a
 * single transaction: tenant, primary domain, system roles, owner, and a first company with a primary
 * branch and a membership. A partial success here is the worst outcome in the platform — a workspace
 * that exists but has no owner cannot be signed into, and cannot be repaired through any interface.
 */
beforeEach(function (): void {
    Notification::fake();
});

/**
 * A valid sign-up payload. Overridden per test so each one states only what it is varying.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signUpPayload(array $overrides = []): array
{
    return [
        'tenant_name' => 'Ceylon Spice Exports',
        'slug' => 'ceylon-spice',
        'owner_first_name' => 'Nadeesha',
        'owner_last_name' => 'Perera',
        'owner_email' => 'nadeesha@ceylonspice.test',
        'owner_password' => 'Workspace#Owner2026',
        'owner_password_confirmation' => 'Workspace#Owner2026',
        'accepts_terms' => true,
        ...$overrides,
    ];
}

function register(array $overrides = []): TestResponse
{
    // No `X-Tenant` header: sign-up is served on the central domain, and a request that resolved a
    // workspace would be creating one from inside another.
    return test()->postJson('/api/v1/workspaces', signUpPayload($overrides));
}

describe('provisioning', function (): void {
    it('creates the whole workspace in one call', function (): void {
        $response = register();

        expect($response->getStatusCode())->toBe(201);

        RowLevelSecurity::bypass(function (): void {
            $tenant = Tenant::query()->where('slug', 'ceylon-spice')->firstOrFail();

            expect($tenant->status)->toBe(TenantStatus::Active)
                ->and($tenant->provisioned_at)->not->toBeNull();

            // The primary domain, or the workspace is unreachable by hostname.
            expect(Domain::query()->where('tenant_id', $tenant->getKey())->where('is_primary', true)->count())
                ->toBe(1);

            // The system roles, or nobody can be granted anything.
            expect(Role::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count())
                ->toBeGreaterThan(0);

            // The owner, holding the owner role.
            $owner = User::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->where('email', 'nadeesha@ceylonspice.test')
                ->firstOrFail();

            // Asserted inside the workspace: role membership is scoped per tenant by spatie's teams
            // feature, so asking outside any tenant context correctly reports no roles at all.
            $isOwner = app(TenantContext::class)->runFor(
                $tenant,
                fn (): bool => $owner->isTenantOwner(),
            );

            expect($isOwner)->toBeTrue();

            // A first company, with a primary branch and the owner as a member.
            $company = Company::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();

            expect($company->is_default)->toBeTrue()
                ->and(Branch::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->where('is_primary', true)->count())
                ->toBe(1)
                ->and(CompanyMembership::query()->withoutGlobalScopes()->where('company_id', $company->getKey())->whereNull('revoked_at')->count())
                ->toBe(1);
        });
    });

    it('lets the new owner sign in immediately', function (): void {
        register();

        // There is nobody else to approve them and the sign-up already proved control of the
        // address, so an e-mail verification gate here would only strand paying customers.
        $response = $this->withHeader('X-Tenant', 'ceylon-spice')->postJson('/api/v1/auth/login', [
            'email' => 'nadeesha@ceylonspice.test',
            'password' => 'Workspace#Owner2026',
        ]);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->json('data.authenticated'))->toBeTrue();
    });

    it('does not force a password change when the owner chose their own', function (): void {
        register();

        $owner = RowLevelSecurity::bypass(
            fn (): User => User::query()->withoutGlobalScopes()->where('email', 'nadeesha@ceylonspice.test')->firstOrFail(),
        );

        expect($owner->must_change_password)->toBeFalse();
    });

    it('returns the workspace with its domain, and no temporary password', function (): void {
        $response = register();

        expect($response->json('data.slug'))->toBe('ceylon-spice')
            // Present only on the back-office path, where nobody chose a password. Returning one
            // here would mean the API had generated a credential the caller did not ask for.
            ->and($response->json('meta.temporary_password'))->toBeNull()
            ->and($response->json('meta.owner_email'))->toBe('nadeesha@ceylonspice.test');
    });

    it('names the company after the workspace when none is given', function (): void {
        register();

        $company = RowLevelSecurity::bypass(
            fn (): Company => Company::query()->withoutGlobalScopes()->firstOrFail(),
        );

        expect($company->name)->toBe('Ceylon Spice Exports');
    });

    it('uses a supplied company name when one is given', function (): void {
        register(['company_name' => 'Spice Trading (Pvt) Ltd']);

        $company = RowLevelSecurity::bypass(
            fn (): Company => Company::query()->withoutGlobalScopes()->firstOrFail(),
        );

        expect($company->name)->toBe('Spice Trading (Pvt) Ltd');
    });

    it('applies the Sri Lankan regional defaults', function (): void {
        register();

        $tenant = RowLevelSecurity::bypass(fn (): Tenant => Tenant::query()->firstOrFail());

        // The platform's first market. A workspace that has to be reconfigured before its first
        // invoice is a workspace that gets abandoned during evaluation.
        expect($tenant->country_code)->toBe('LK')
            ->and($tenant->currency_code)->toBe('LKR')
            ->and($tenant->timezone)->toBe('Asia/Colombo');
    });

    it('does not leak credentials in the response', function (): void {
        $response = register();

        expect($response)->toNotExposeFields('password', 'owner_password', 'two_factor_secret');
    });
});

describe('rejection', function (): void {
    it('refuses a slug that is already taken', function (): void {
        register();

        $response = register(['owner_email' => 'someone@else.test']);

        // A conflict rather than a validation error, so the client can offer alternatives instead of
        // showing "invalid" next to an address the user typed correctly.
        expect($response->getStatusCode())->toBe(409);
    });

    it('refuses a reserved slug', function (): void {
        config(['asids.tenancy.reserved_slugs' => ['admin', 'api']]);

        $response = register(['slug' => 'admin']);

        // `admin.asids.lk` belonging to a customer would let them serve content from a hostname
        // every other customer has been taught to trust.
        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('slug');
    });

    it('refuses a slug that is not a valid DNS label', function (): void {
        $response = register(['slug' => 'Not A Slug!']);

        // It becomes a hostname. An invalid label produces a workspace nobody can reach.
        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('slug');
    });

    it('refuses a sign-up that does not accept the terms', function (): void {
        $response = register(['accepts_terms' => false]);

        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('accepts_terms');
    });

    it('refuses an unconfirmed password', function (): void {
        $response = register(['owner_password_confirmation' => 'Something#Else2026']);

        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('owner_password');
    });

    it('refuses a password that fails the platform policy', function (): void {
        $response = register([
            'owner_password' => 'password',
            'owner_password_confirmation' => 'password',
        ]);

        // The policy is `Password::defaults()`, configured once in PlatformServiceProvider, so
        // sign-up cannot drift from invitation acceptance or a self-service change.
        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('owner_password');
    });

    it('leaves nothing behind when the sign-up is rejected', function (): void {
        register(['slug' => 'Not A Slug!']);

        // The whole thing is one transaction. A rejected sign-up that left a tenant row would make
        // the slug permanently unavailable to the customer who just failed to claim it.
        RowLevelSecurity::bypass(function (): void {
            expect(Tenant::query()->count())->toBe(0)
                ->and(User::query()->withoutGlobalScopes()->count())->toBe(0);
        });
    });
});

describe('availability check', function (): void {
    it('reports an unused slug as available', function (): void {
        $response = $this->getJson('/api/v1/workspaces/availability?slug=brand-new');

        expect($response)->toBeEnvelope()
            ->and($response->json('data.available'))->toBeTrue()
            ->and($response->json('data.valid'))->toBeTrue()
            ->and($response->json('data.suggestions'))->toBe([]);
    });

    it('reports a taken slug as unavailable and suggests alternatives', function (): void {
        register();

        $response = $this->getJson('/api/v1/workspaces/availability?slug=ceylon-spice');

        // "Taken" with no alternative is where sign-up funnels lose people.
        expect($response->json('data.available'))->toBeFalse()
            ->and($response->json('data.suggestions'))->not->toBe([]);
    });

    it('reports a malformed slug as invalid without suggesting anything', function (): void {
        $response = $this->getJson('/api/v1/workspaces/availability?slug=Not Valid');

        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.available'))->toBeFalse()
            // Nothing to suggest: the problem is the shape, and the user is still typing.
            ->and($response->json('data.suggestions'))->toBe([]);
    });

    it('reports a reserved slug as unavailable', function (): void {
        config(['asids.tenancy.reserved_slugs' => ['admin']]);

        $response = $this->getJson('/api/v1/workspaces/availability?slug=admin');

        expect($response->json('data.available'))->toBeFalse();
    });

    it('treats an absent slug as invalid rather than erroring', function (): void {
        $response = $this->getJson('/api/v1/workspaces/availability');

        expect($response->getStatusCode())->toBe(200)
            ->and($response->json('data.valid'))->toBeFalse();
    });
});

describe('hostname resolution', function (): void {
    it('resolves the workspace from its primary hostname', function (): void {
        register();

        $host = RowLevelSecurity::bypass(
            fn (): string => (string) Domain::query()->where('is_primary', true)->firstOrFail()->domain,
        );

        // An absolute URL rather than `withHeader('Host', …)`. Laravel builds the test request with
        // `Request::create($uri, …)`, which sets HTTP_HOST from the URI *after* merging custom
        // server variables — so a Host header is silently overwritten and the request arrives on the
        // central domain. That failure looks exactly like a resolution bug.
        $response = $this->postJson("http://{$host}/api/v1/auth/login", [
            'email' => 'nadeesha@ceylonspice.test',
            'password' => 'Workspace#Owner2026',
        ]);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->json('data.authenticated'))->toBeTrue();
    });
});
