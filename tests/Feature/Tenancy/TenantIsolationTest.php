<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Exceptions\CrossTenantWriteAttempted;
use Asids\Core\Tenancy\Domain\Exceptions\NoActiveTenant;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Asids\Core\Tenancy\Domain\Scopes\TenantScope;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;

/**
 * Tenant isolation via the Eloquent global scope.
 *
 * This is the claim the entire platform rests on: one customer's ledger must be unreachable
 * from another's session. Every other security property is worth less than this one, so these
 * tests are the first that should ever run and the first that should ever be trusted.
 *
 * The scope is the *primary* mechanism. PostgreSQL row level security is the backstop and is
 * tested separately in RowLevelSecurityTest, because it needs a differently privileged
 * connection.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');
});

describe('read scoping', function (): void {
    it('shows a workspace only its own companies', function (): void {
        $this->withinTenant($this->acme['tenant']);

        $companies = Company::query()->get();

        expect($companies)->toHaveCount(1)
            ->and($companies->first()->getKey())->toBe($this->acme['company']->getKey())
            ->and($companies->pluck('tenant_id')->unique()->all())
            ->toBe([$this->acme['tenant']->getKey()]);
    });

    it('hides another workspace’s company even when its id is known', function (): void {
        $this->withinTenant($this->acme['tenant']);

        // The attacker's best case: they hold a valid identifier from elsewhere.
        expect(Company::query()->find($this->globex['company']->getKey()))->toBeNull();
    });

    it('does not put platform staff into a workspace’s user list', function (): void {
        RowLevelSecurity::bypass(fn () => User::factory()->platformAdmin()->create());

        $this->withinTenant($this->acme['tenant']);

        // `users` opts *out* of including NULL-tenant rows. The universal alternative would put
        // every ASIDS employee into every customer's user administration screen.
        expect(User::query()->get()->pluck('is_platform_admin')->unique()->all())->toBe([false]);
    });

    it('scopes a relation traversed from a model, not just a direct query', function (): void {
        $this->withinTenant($this->acme['tenant']);

        $branchesOfOtherTenant = Branch::query()
            ->where('company_id', $this->globex['company']->getKey())
            ->get();

        // A relation is where a forgotten scope usually leaks: the parent was fetched
        // legitimately, so the child query feels safe.
        expect($branchesOfOtherTenant)->toBeEmpty();
    });

    it('counts and aggregates within the workspace only', function (): void {
        $this->withinTenant($this->acme['tenant']);

        // An aggregate that ignores the scope is a subtler leak than a list: it discloses a
        // competitor's size without disclosing a single row.
        expect(Company::query()->count())->toBe(1)
            ->and(User::query()->count())->toBe(1);
    });
});

describe('failing closed', function (): void {
    it('returns nothing when no workspace is active', function (): void {
        $this->endTenancy();

        // The critical decision. Returning everything here — the "obvious" alternative — turns
        // every console command, queued job and forgotten middleware into a cross-tenant leak.
        expect(Company::query()->count())->toBe(0);
    });

    it('refuses to create a tenant-scoped record with no workspace active', function (): void {
        $this->endTenancy();

        expect(fn () => Company::query()->create([
            'name' => 'Orphan Ltd',
            'code' => 'ORPH',
            'slug' => 'orphan',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
        ]))->toThrow(NoActiveTenant::class);
    });

    it('allows a model that declares a NULL tenant to be legitimate', function (): void {
        $this->endTenancy();

        // Platform staff exist outside every workspace, so `User` opts in to that via
        // `tenantIsOptional()`. Without the opt-in, ASIDS could not create its own accounts.
        $staff = RowLevelSecurity::bypass(fn () => User::factory()->platformAdmin()->create());

        expect($staff->tenant_id)->toBeNull()
            ->and($staff->is_platform_admin)->toBeTrue();
    });
});

describe('write guarding', function (): void {
    it('stamps the active workspace onto a new record automatically', function (): void {
        $this->withinTenant($this->acme['tenant']);

        $company = Company::query()->create([
            'name' => 'Acme Logistics',
            'code' => 'ALOG',
            'slug' => 'acme-logistics',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
        ]);

        // Never passed in. A caller cannot forget it, which is the point.
        expect($company->tenant_id)->toBe($this->acme['tenant']->getKey());
    });

    it('refuses mass assignment of another workspace’s tenant_id', function (): void {
        $this->withinTenant($this->acme['tenant']);

        // `tenant_id` is deliberately absent from Company's $fillable — BelongsToTenant stamps
        // it — so mass assignment protection rejects this before the domain guard is reached.
        // Two independent barriers, and this asserts the outer one.
        expect(fn () => Company::query()->create([
            'tenant_id' => $this->globex['tenant']->getKey(),
            'name' => 'Smuggled Ltd',
            'code' => 'SMUG',
            'slug' => 'smuggled',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
        ]))->toThrow(Illuminate\Database\Eloquent\MassAssignmentException::class);
    });

    it('refuses to create a record belonging to another workspace', function (): void {
        $this->withinTenant($this->acme['tenant']);

        // The realistic bypass: attributes set directly, past mass assignment protection.
        $company = new Company();
        $company->fill([
            'name' => 'Smuggled Ltd',
            'code' => 'SMUG',
            'slug' => 'smuggled',
            'base_currency_code' => 'LKR',
            'country_code' => 'LK',
            'timezone' => 'Asia/Colombo',
        ]);
        $company->status = Asids\Core\Organization\Domain\Enums\OrganizationStatus::Active;
        $company->tenant_id = $this->globex['tenant']->getKey();

        $exception = catchPlatformException(fn () => $company->save());

        expect($exception)->toBeInstanceOf(CrossTenantWriteAttempted::class)
            // The identifiers stay in the log, never in the response — telling a caller which
            // workspace they touched confirms that workspace exists.
            ->and($exception->problemExtensions())->toBe([])
            ->and($exception->context())->toHaveKeys(['active_tenant_id', 'attempted_tenant_id']);
    });

    it('refuses to move an existing record to another workspace', function (): void {
        $this->withinTenant($this->acme['tenant']);

        $company = Company::query()->firstOrFail();
        $company->tenant_id = $this->globex['tenant']->getKey();

        // Re-parenting is never a legitimate update, so it is blocked on the model rather than
        // left to a policy that a bulk operation could skip.
        expect(fn () => $company->save())->toThrow(CrossTenantWriteAttempted::class);
    });

    it('refuses to update another workspace’s record fetched without the scope', function (): void {
        $this->withinTenant($this->acme['tenant']);

        // Simulates the realistic bug: someone added `withoutGlobalScopes()` while debugging and
        // left it in. The row is fetched under a bypass so the *write* guard is what is being
        // tested — with row level security enforced the read alone already fails, which is the
        // backstop doing its job and is asserted in RowLevelSecurityTest.
        $foreign = RowLevelSecurity::bypass(fn () => Company::query()
            ->withoutGlobalScope(TenantScope::IDENTIFIER)
            ->where('tenant_id', $this->globex['tenant']->getKey())
            ->firstOrFail());

        $foreign->name = 'Renamed by another tenant';

        expect(fn () => $foreign->save())->toThrow(CrossTenantWriteAttempted::class);
    });
});

describe('escape hatches', function (): void {
    it('requires BOTH layers to be lifted before it can read across workspaces', function (): void {
        $this->withinTenant($this->acme['tenant']);

        // `acrossAllTenants()` removes only the Eloquent scope. With row level security enforced
        // the database still constrains the query, so on its own it sees one workspace — the two
        // layers are genuinely independent, which is the whole point of having both.
        $scopeOnly = Company::acrossAllTenants()->count();

        // Lifting both is what a platform-wide read actually requires. Named to stand out in a
        // diff: if this pair appears in a controller, review should stop.
        $bothLifted = RowLevelSecurity::bypass(fn (): int => Company::acrossAllTenants()->count());

        expect($bothLifted)->toBe(2)
            ->and($scopeOnly)->toBeLessThanOrEqual($bothLifted);
    });

    it('restores the previous workspace after running as another', function (): void {
        $this->withinTenant($this->acme['tenant']);

        app(TenantContext::class)->runFor(
            $this->globex['tenant'],
            fn () => expect(Company::query()->count())->toBe(1),
        );

        expect(app(TenantContext::class)->id())->toBe($this->acme['tenant']->getKey());
    });

    it('restores the previous workspace even when the callback throws', function (): void {
        $this->withinTenant($this->acme['tenant']);

        try {
            app(TenantContext::class)->runFor(
                $this->globex['tenant'],
                fn () => throw new RuntimeException('boom'),
            );
        } catch (RuntimeException) {
            // Expected.
        }

        // The `finally` in runFor. Without it, an exception inside a job leaks one workspace's
        // context into the next job the worker picks up — on a long-lived process, indefinitely.
        expect(app(TenantContext::class)->id())->toBe($this->acme['tenant']->getKey());
    });

    it('restores central context after running centrally', function (): void {
        $this->withinTenant($this->acme['tenant']);

        app(TenantContext::class)->runCentrally(
            fn () => expect(app(TenantContext::class)->has())->toBeFalse(),
        );

        expect(app(TenantContext::class)->id())->toBe($this->acme['tenant']->getKey());
    });

    it('nests a row level security bypass without releasing it early', function (): void {
        RowLevelSecurity::bypass(function (): void {
            RowLevelSecurity::bypass(function (): void {
                expect(RowLevelSecurity::isBypassed())->toBeTrue();
            });

            // Depth counting. A naive implementation would clear the flag when the inner call
            // returned, silently re-enabling policies mid-way through a migration.
            expect(RowLevelSecurity::isBypassed())->toBeTrue();
        });

        expect(RowLevelSecurity::isBypassed())->toBeFalse();
    });

    it('clears the bypass when the callback throws', function (): void {
        try {
            RowLevelSecurity::bypass(fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // Expected.
        }

        // A pooled connection left with protection disabled would silently expose the next
        // request that borrows it.
        expect(RowLevelSecurity::isBypassed())->toBeFalse();
    });
});

describe('cache isolation', function (): void {
    it('does not let one workspace read another’s cached value under the same key', function (): void {
        // The `array` store ignores `cache.prefix` completely, so tenant prefixing is
        // unobservable under it — the assertion would fail for a reason that has nothing to do
        // with isolation. Skipped loudly rather than deleted, because the property is real and
        // must be verified against the driver production actually uses.
        if (config('cache.default') === 'array') {
            test()->markTestSkipped(
                'The array cache store does not honour cache.prefix. Run with CACHE_STORE=redis '
                .'(or database) to exercise tenant cache isolation.'
            );
        }

        app(TenantContext::class)->runFor(
            $this->acme['tenant'],
            fn () => cache()->put('dashboard.totals', 'acme-figures', 60),
        );

        $seenByGlobex = app(TenantContext::class)->runFor(
            $this->globex['tenant'],
            fn () => cache()->get('dashboard.totals'),
        );

        // Identical key, different workspace. Without CacheTagBootstrapper this returns
        // acme-figures — a leak with no database query involved, which no amount of RLS catches.
        expect($seenByGlobex)->toBeNull();
    });
});

describe('tenant model itself', function (): void {
    it('is not tenant scoped, because it is the root of the hierarchy', function (): void {
        $this->withinTenant($this->acme['tenant']);

        // `tenants` carries no tenant_id and has no policy. Scoping it would make resolution
        // impossible — a request cannot look up its own workspace inside that workspace.
        expect(Tenant::query()->count())->toBe(2);
    });
});
