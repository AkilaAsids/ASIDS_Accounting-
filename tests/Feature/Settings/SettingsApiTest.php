<?php

declare(strict_types=1);

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue;
use Asids\Core\Tenancy\Infrastructure\RowLevelSecurity;
use Illuminate\Testing\TestResponse;

/**
 * The settings HTTP surface.
 *
 * SettingsResolutionTest covers the resolver and the service. This covers the part the SPA depends
 * on absolutely: the endpoint returns the *catalogue*, not just values, so adding a setting on the
 * server makes it appear in the interface with the right control, label and help text and no
 * front-end change. If the metadata is wrong the screen renders a text box for a boolean.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];
    $this->viewer = $this->createUserWithRole($this->acme['tenant'], 'viewer');
});

function asSettings(User $user, string $method, string $uri, array $payload = []): TestResponse
{
    $authenticated = RowLevelSecurity::bypass(static fn (): ?User => $user->fresh());

    return test()->actingAs($authenticated ?? $user)
        ->withHeader('X-Tenant', 'acme')
        ->json($method, $uri, $payload);
}

describe('reading settings', function (): void {
    it('returns settings grouped, with the metadata the form needs', function (): void {
        $response = asSettings($this->owner, 'GET', '/api/v1/settings?scope=user');

        expect($response)->toBeEnvelope();

        $field = collect($response->json('data'))
            ->flatMap(fn (array $group): array => $group['settings'] ?? [])
            ->first();

        // Server-driven: the control is chosen from `type`, the options populate a select, and
        // `is_overridden` decides whether the reset link appears. Each one absent is a broken field.
        expect($field)->toHaveKeys([
            'key', 'label', 'description', 'type', 'value', 'default', 'is_overridden', 'overridable_at',
        ]);
    });

    it('reports whether a value is inherited or overridden', function (): void {
        $before = collect(asSettings($this->owner, 'GET', '/api/v1/settings?scope=tenant')->json('data'))
            ->flatMap(fn (array $group): array => $group['settings'] ?? [])
            ->firstWhere('key', 'localisation.date_format');

        expect($before['is_overridden'])->toBeFalse();

        asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'tenant',
            'settings' => ['localisation.date_format' => 'Y-m-d'],
        ])->assertSuccessful();

        $after = collect(asSettings($this->owner, 'GET', '/api/v1/settings?scope=tenant')->json('data'))
            ->flatMap(fn (array $group): array => $group['settings'] ?? [])
            ->firstWhere('key', 'localisation.date_format');

        // Shown, not hidden: without it a user cannot tell a deliberate choice from a value that will
        // change the next time the workspace's does.
        expect($after['is_overridden'])->toBeTrue()
            ->and($after['value'])->toBe('Y-m-d');
    });

    it('serves the bootstrap payload the shell starts from', function (): void {
        $response = asSettings($this->owner, 'GET', '/api/v1/settings/bootstrap');

        expect($response)->toBeEnvelope()
            ->and($response->json('data'))->not->toBeEmpty();
    });

    it('never exposes a non-public setting through bootstrap', function (): void {
        $response = asSettings($this->owner, 'GET', '/api/v1/settings/bootstrap');

        $keys = array_keys((array) $response->json('data'));

        // This payload ships to the browser. `security.password_expiry_days` would tell an attacker
        // how long a stolen password stays useful.
        foreach ($keys as $key) {
            expect(SettingsCatalogue::find((string) $key)?->public)
                ->toBeTrue();
        }
    });
});

describe('writing settings', function (): void {
    it('writes personal settings for the caller', function (): void {
        $response = asSettings($this->viewer, 'PUT', '/api/v1/settings', [
            'scope' => 'user',
            'settings' => ['localisation.date_format' => 'Y-m-d'],
        ]);

        expect($response)->toBeEnvelope();
    });

    it('refuses workspace settings to a caller without the permission', function (): void {
        $response = asSettings($this->viewer, 'PUT', '/api/v1/settings', [
            'scope' => 'tenant',
            'settings' => ['localisation.date_format' => 'Y-m-d'],
        ]);

        // Authorised in the form request against the *scope*, so a viewer editing their own
        // preferences and a viewer editing everyone's are different answers to the same endpoint.
        expect($response->getStatusCode())->toBe(403);
    });

    it('requires a target for company scope', function (): void {
        $response = asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'company',
            'settings' => ['localisation.number_format' => 'western'],
        ]);

        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('scope_id');
    });

    it('forbids a target for workspace scope', function (): void {
        $response = asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'tenant',
            'scope_id' => $this->company->getKey(),
            'settings' => ['localisation.date_format' => 'Y-m-d'],
        ]);

        // The table has a check constraint asserting the same thing. Reaching it would surface as a
        // constraint name rather than a field-level message.
        expect($response)->toBeProblem('validation-failed', 422)
            ->and($response->json('errors'))->toHaveKey('scope_id');
    });

    it('refuses an unknown scope', function (): void {
        $response = asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'galaxy',
            'settings' => ['localisation.date_format' => 'Y-m-d'],
        ]);

        expect($response->getStatusCode())->toBeIn([403, 422]);
    });

    it('refuses an empty settings payload', function (): void {
        $response = asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'tenant',
            'settings' => [],
        ]);

        expect($response)->toBeProblem('validation-failed', 422);
    });

    it('refuses a value the setting does not allow', function (): void {
        $response = asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'tenant',
            'settings' => ['localisation.date_format' => 'not-a-format'],
        ]);

        expect($response)->toBeProblem('invalid-setting-value');
    });

    it('writes company settings with a target', function (): void {
        $response = asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'company',
            'scope_id' => $this->company->getKey(),
            'settings' => ['localisation.number_format' => 'western'],
        ]);

        expect($response)->toBeEnvelope();
    });
});

describe('resetting a setting', function (): void {
    it('removes an override so the next layer applies again', function (): void {
        asSettings($this->owner, 'PUT', '/api/v1/settings', [
            'scope' => 'tenant',
            'settings' => ['localisation.date_format' => 'Y-m-d'],
        ])->assertSuccessful();

        $response = asSettings($this->owner, 'DELETE', '/api/v1/settings/localisation.date_format?scope=tenant');

        expect($response->getStatusCode())->toBeIn([200, 204]);

        $field = collect(asSettings($this->owner, 'GET', '/api/v1/settings?scope=tenant')->json('data'))
            ->flatMap(fn (array $group): array => $group['settings'] ?? [])
            ->firstWhere('key', 'localisation.date_format');

        expect($field['is_overridden'])->toBeFalse();
    });

    it('refuses a reset the caller is not allowed to make', function (): void {
        $response = asSettings($this->viewer, 'DELETE', '/api/v1/settings/localisation.date_format?scope=tenant');

        expect($response->getStatusCode())->toBe(403);
    });
});
