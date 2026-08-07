<?php

declare(strict_types=1);

use Asids\Core\Authorization\Application\DTOs\RoleData;
use Asids\Core\Authorization\Application\Services\RoleService;
use Asids\Core\Authorization\Domain\Models\Role;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Role and permission administration over HTTP.
 *
 * PrivilegeEscalationTest covers the service's refusals directly. This covers the same rules as a
 * client experiences them — through the policy, the form request and the response — plus the parts
 * that only exist at the edge: the permission matrix the roles screen renders itself from, and the
 * step-up requirement on ownership transfer.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->globex = $this->createWorkspace('globex');

    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->administrator = $this->createUserWithRole($this->acme['tenant'], 'administrator', [
        'email' => 'admin@acme.test',
    ]);
    $this->accountant = $this->createUserWithRole($this->acme['tenant'], 'accountant', [
        'email' => 'accountant@acme.test',
    ]);
});

function asRoleUser(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

describe('listing roles', function (): void {
    it('returns the workspace’s roles with the metadata the screen needs', function (): void {
        $response = asRoleUser($this->owner, 'GET', '/api/v1/roles');

        expect($response)->toBeEnvelope();

        $first = $response->json('data.0');

        // The roles screen is driven entirely by this payload — it renders a permission matrix and
        // decides which rows are editable — so each of these absent would be a blank column.
        expect($first)->toHaveKeys(['id', 'name', 'label', 'level', 'capabilities']);
    });

    it('reports which roles the caller may actually grant', function (): void {
        $response = asRoleUser($this->administrator, 'GET', '/api/v1/roles');

        $grantable = collect($response->json('data'))
            ->filter(fn (array $role): bool => $role['capabilities']['grantable_by_current_user'] === true)
            ->pluck('name')
            ->all();

        // Offering a role the server will refuse is a form that fails after submission for a reason
        // the user cannot see. An administrator cannot grant owner, or their own level.
        expect($grantable)->not->toContain('owner')
            ->and($grantable)->not->toContain('administrator');
    });

    it('never returns another workspace’s roles', function (): void {
        $response = asRoleUser($this->owner, 'GET', '/api/v1/roles');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $foreign = RowLevelSecurity::bypass(fn (): array => Role::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->globex['tenant']->getKey())
            ->pluck('id')
            ->all());

        expect(array_intersect($ids, $foreign))->toBe([]);
    });

    it('counts the users holding each role', function (): void {
        $response = asRoleUser($this->owner, 'GET', '/api/v1/roles');

        $accountantRole = collect($response->json('data'))->firstWhere('name', 'accountant');

        // This is the `withCount` that used to 500: spatie resolved the relation's model from the
        // role's `guard_name`, which is null on a query builder rather than a hydrated instance.
        expect($accountantRole)->not->toBeNull()
            ->and($accountantRole['assigned_user_count'] ?? null)->toBe(1);
    });

    it('refuses a caller without the view permission', function (): void {
        $viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer');

        expect(asRoleUser($viewer, 'GET', '/api/v1/roles')->getStatusCode())->toBe(403);
    });
});

describe('the permission catalogue endpoint', function (): void {
    it('returns the catalogue grouped for the matrix', function (): void {
        $response = asRoleUser($this->owner, 'GET', '/api/v1/permissions');

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->not->toBeEmpty();
    });

    it('never offers a platform capability as tenant-grantable', function (): void {
        $response = asRoleUser($this->owner, 'GET', '/api/v1/permissions');

        // Grouped module → resource → permissions, which is the shape the matrix renders directly.
        $names = collect($response->json('data'))
            ->flatMap(fn (array $module): array => $module['resources'] ?? [])
            ->flatMap(fn (array $resource): array => $resource['permissions'] ?? [])
            ->pluck('name')
            ->all();

        // A customer role that could grant `platform.*` would let a paying customer operate the
        // platform itself.
        //
        // Asserted over the collected list rather than in a loop: an empty payload would make a
        // per-item loop pass without executing a single assertion, which is a leak check that
        // silently stops checking the moment the endpoint's shape changes.
        expect($names)->not->toBeEmpty();

        expect(array_values(array_filter(
            $names,
            static fn (string $name): bool => str_starts_with($name, 'platform.'),
        )))->toBe([]);
    });
});

describe('creating a role', function (): void {
    it('creates a role with the requested permissions', function (): void {
        $response = asRoleUser($this->owner, 'POST', '/api/v1/roles', [
            'label' => 'Payroll Clerk',
            'permissions' => ['identity.users.view'],
            'level' => 20,
        ]);

        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.label'))->toBe('Payroll Clerk');
    });

    it('refuses a permission that is not in the catalogue', function (): void {
        $response = asRoleUser($this->owner, 'POST', '/api/v1/roles', [
            'label' => 'Invented',
            'permissions' => ['identity.users.invent'],
        ]);

        // Validated against the catalogue, not the table: the catalogue is the source of truth, and
        // rejecting at the boundary means the service never sees a name it would have to interpret.
        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('permissions.0');
    });

    it('refuses a platform capability at the boundary', function (): void {
        $response = asRoleUser($this->owner, 'POST', '/api/v1/roles', [
            'label' => 'Escalation',
            'permissions' => ['platform.tenants.view'],
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('clamps a requested level to strictly below the creator’s own', function (): void {
        $response = asRoleUser($this->administrator, 'POST', '/api/v1/roles', [
            'label' => 'Sneaky',
            'permissions' => [],
            'level' => 99,
        ]);

        $administratorLevel = RowLevelSecurity::bypass(
            fn (): int => (int) Role::query()->where('name', 'administrator')->firstOrFail()->level,
        );

        // Clamped rather than refused: the request is legitimate, only the level is not, and a
        // refusal would tell the caller exactly where the ceiling is.
        expect($response->getStatusCode())->toBe(201)
            ->and($response->json('data.level'))->toBeLessThan($administratorLevel);
    });

    it('refuses a caller without the manage permission', function (): void {
        $response = asRoleUser($this->accountant, 'POST', '/api/v1/roles', [
            'label' => 'Nope',
            'permissions' => [],
        ]);

        expect($response->getStatusCode())->toBe(403);
    });
});

describe('updating and deleting a role', function (): void {
    it('changes a custom role’s permissions', function (): void {
        $created = asRoleUser($this->owner, 'POST', '/api/v1/roles', [
            'label' => 'Payroll Clerk',
            'permissions' => ['identity.users.view'],
        ])->json('data.id');

        $response = asRoleUser($this->owner, 'PUT', "/api/v1/roles/{$created}", [
            'label' => 'Payroll Lead',
            'permissions' => ['identity.users.view', 'organization.companies.view'],
        ]);

        expect($response)->toBeEnvelope()
            ->and($response->json('data.label'))->toBe('Payroll Lead');
    });

    it('ignores an attempt to rename a built-in role rather than failing the request', function (): void {
        $accountantRole = RowLevelSecurity::bypass(
            fn (): Role => Role::query()->where('name', 'accountant')->firstOrFail(),
        );

        $response = asRoleUser($this->owner, 'PUT', "/api/v1/roles/{$accountantRole->getKey()}", [
            'label' => 'Renamed',
            'permissions' => ['identity.users.view'],
        ]);

        // Succeeds, and the label does not move. The form request replaces a system role's submitted
        // label with the stored one, so a client rendering a disabled-but-populated field still
        // saves its permission changes instead of failing validation on a field the user cannot edit.
        expect($response)->toBeEnvelope()
            ->and($response->json('data.label'))->toBe($accountantRole->label)
            ->and($accountantRole->refresh()->label)->toBe($accountantRole->label);
    });

    it('refuses to rename a built-in role when the request layer is bypassed', function (): void {
        $accountantRole = RowLevelSecurity::bypass(
            fn (): Role => Role::query()->where('name', 'accountant')->firstOrFail(),
        );

        // The service's own guard, reached by a caller that is not the form request — a console
        // command, or a future endpoint. The role's *name* is referenced in code, seeders and
        // documentation, and its label is what every support conversation refers to.
        $exception = catchPlatformException(fn () => app(RoleService::class)
            ->update(
                $accountantRole,
                new RoleData(
                    label: 'Renamed',
                    description: null,
                    permissionNames: [],
                ),
                $this->owner,
            ));

        expect($exception->problemCode())->toBe('system-role-protected');
    });

    it('deletes a custom role that nobody holds', function (): void {
        $created = asRoleUser($this->owner, 'POST', '/api/v1/roles', [
            'label' => 'Temporary',
            'permissions' => [],
        ])->json('data.id');

        expect(asRoleUser($this->owner, 'DELETE', "/api/v1/roles/{$created}")->getStatusCode())->toBe(204);
    });

    it('refuses to delete a role that users still hold', function (): void {
        $accountantRole = RowLevelSecurity::bypass(
            fn (): Role => Role::query()->where('name', 'accountant')->firstOrFail(),
        );

        $response = asRoleUser($this->owner, 'DELETE', "/api/v1/roles/{$accountantRole->getKey()}");

        // Deleting it would silently strip every holder of their capabilities, and the audit trail
        // would show no change to any user.
        expect($response->getStatusCode())->toBe(422);
    });
});

describe('assigning roles', function (): void {
    it('replaces a user’s roles wholesale', function (): void {
        $bookkeeper = RowLevelSecurity::bypass(
            fn (): Role => Role::query()->where('name', 'bookkeeper')->firstOrFail(),
        );

        $response = asRoleUser($this->owner, 'PUT', "/api/v1/users/{$this->accountant->getKey()}/roles", [
            'role_ids' => [$bookkeeper->getKey()],
        ]);

        expect($response)->toBeEnvelope()
            ->and(collect($response->json('data.roles'))->pluck('name')->all())->toBe(['bookkeeper']);
    });

    it('accepts an empty set as “remove every role”', function (): void {
        $response = asRoleUser($this->owner, 'PUT', "/api/v1/users/{$this->accountant->getKey()}/roles", [
            'role_ids' => [],
        ]);

        // `present` rather than `required` on the field is what allows this. Without it, revoking a
        // user's last role would be impossible through the API.
        expect($response)->toBeEnvelope()
            ->and($response->json('data.roles'))->toBe([]);
    });

    it('refuses to assign the owner role', function (): void {
        $ownerRole = RowLevelSecurity::bypass(
            fn (): Role => Role::query()->where('is_owner', true)->firstOrFail(),
        );

        $response = asRoleUser($this->owner, 'PUT', "/api/v1/users/{$this->accountant->getKey()}/roles", [
            'role_ids' => [$ownerRole->getKey()],
        ]);

        // Ownership moves only through the transfer endpoint, which requires a second factor. If it
        // could be granted as an ordinary role, a hijacked administrator session would be enough.
        expect($response->getStatusCode())->toBe(422);
    });

    it('refuses to assign a role at or above the actor’s own level', function (): void {
        $administratorRole = RowLevelSecurity::bypass(
            fn (): Role => Role::query()->where('name', 'administrator')->firstOrFail(),
        );

        $response = asRoleUser($this->administrator, 'PUT', "/api/v1/users/{$this->accountant->getKey()}/roles", [
            'role_ids' => [$administratorRole->getKey()],
        ]);

        expect($response->getStatusCode())->toBe(422);
    });

    it('refuses an unknown role id rather than silently ignoring it', function (): void {
        $response = asRoleUser($this->owner, 'PUT', "/api/v1/users/{$this->accountant->getKey()}/roles", [
            'role_ids' => [(string) Str::uuid7()],
        ]);

        // Silently dropping it would report success while leaving the user with fewer capabilities
        // than the administrator believes they granted.
        expect($response->getStatusCode())->toBe(422);
    });

    it('cannot assign a role belonging to another workspace', function (): void {
        $foreign = RowLevelSecurity::bypass(fn (): Role => Role::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->globex['tenant']->getKey())
            ->where('name', 'accountant')
            ->firstOrFail());

        $response = asRoleUser($this->owner, 'PUT', "/api/v1/users/{$this->accountant->getKey()}/roles", [
            'role_ids' => [$foreign->getKey()],
        ]);

        expect($response->getStatusCode())->toBe(422);
    });
});

describe('ownership transfer', function (): void {
    it('proceeds for an owner who has no second factor to step up to', function (): void {
        $response = asRoleUser($this->owner, 'POST', "/api/v1/users/{$this->administrator->getKey()}/transfer-ownership");

        // Deliberate, and worth stating plainly because it bounds what the step-up actually buys.
        // The middleware cannot demand a code from someone who has never enrolled, and refusing
        // outright would make ownership transfer unreachable for exactly the workspaces most likely
        // to need it — a sole owner who has not set up an authenticator. Without workspace-level
        // enforcement, the permission check is the only control on this route.
        expect($response)->toBeEnvelope()
            ->and($this->administrator->refresh()->isTenantOwner())->toBeTrue();
    });

    it('demands a recent second factor from an owner who has one', function (): void {
        RowLevelSecurity::bypass(fn () => $this->owner->forceFill([
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'two_factor_enrolled_at' => now(),
            'two_factor_confirmed_at' => now(),
        ])->save());

        $response = asRoleUser($this->owner, 'POST', "/api/v1/users/{$this->administrator->getKey()}/transfer-ownership");

        // 428 with a step-up code, which the client turns into a prompt rather than an error. Enrolled
        // and confirmed at sign-in is not enough: the proof has to be recent, or a session hijacked
        // hours later would carry it.
        expect($response->getStatusCode())->toBe(428)
            ->and($response->json('type'))->toContain('two-factor')
            ->and($this->administrator->refresh()->isTenantOwner())->toBeFalse();
    });

    it('refuses a caller who is not the owner', function (): void {
        $response = asRoleUser($this->administrator, 'POST', "/api/v1/users/{$this->accountant->getKey()}/transfer-ownership");

        // 403 or 428 depending on which guard is reached first; either way it is refused, and the
        // administrator does not become the owner.
        expect($response->getStatusCode())->toBeIn([403, 428])
            ->and($this->administrator->refresh()->isTenantOwner())->toBeFalse();
    });
});
