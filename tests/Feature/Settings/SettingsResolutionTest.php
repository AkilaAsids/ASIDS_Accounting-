<?php

declare(strict_types=1);

use Asids\Core\Organization\Application\DTOs\CreateCompanyData;
use Asids\Core\Organization\Application\Services\CompanyService;
use Asids\Core\Settings\Application\Services\SettingsResolver;
use Asids\Core\Settings\Application\Services\SettingsService;
use Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Models\Setting;
use Asids\Core\Tenancy\Application\Services\TenantContext;

/**
 * Hierarchical settings.
 *
 * The property under test is the resolution order: user, then company, then workspace, then the
 * value shipped in code. Every layer is optional and a missing layer must fall through — not to
 * null, and not to the layer's *own* default, but to the next layer that has an opinion. The
 * failure mode this guards against is quiet: a company override that is silently ignored looks
 * exactly like a company that never set one.
 */
beforeEach(function (): void {
    $this->acme = $this->createWorkspace('acme');
    $this->withinTenant($this->acme['tenant']);

    $this->owner = $this->acme['owner'];
    $this->company = $this->acme['company'];

    $this->settings = app(SettingsService::class);
    $this->resolver = app(SettingsResolver::class);
});

/** A setting overridable at all three tenant-side scopes, so the full chain can be exercised. */
const LAYERED_KEY = 'localisation.date_format';

describe('four level resolution', function (): void {
    it('falls back to the value shipped in code when nothing is overridden', function (): void {
        $definition = SettingsCatalogue::find(LAYERED_KEY);

        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe($definition?->default);
    });

    it('prefers a workspace override to the shipped default', function (): void {
        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);

        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe('Y-m-d');
    });

    it('prefers a company override to the workspace value', function (): void {
        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);
        $this->settings->set(LAYERED_KEY, 'd M Y', SettingScope::Company, $this->company->getKey(), $this->owner);

        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe('d M Y');
    });

    it('prefers a personal override to everything else', function (): void {
        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);
        $this->settings->set(LAYERED_KEY, 'd M Y', SettingScope::Company, $this->company->getKey(), $this->owner);
        $this->settings->set(LAYERED_KEY, 'm/d/Y', SettingScope::User, $this->owner->getKey(), $this->owner);

        // The reason personal wins: an expatriate accountant reading d/m/Y as m/d/Y misreads every
        // date on the screen, and no workspace-wide choice can fix that for them alone.
        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe('m/d/Y');
    });

    it('does not apply one user’s override to another user', function (): void {
        $other = $this->createUserWithRole($this->acme['tenant'], 'bookkeeper');

        $this->settings->set(LAYERED_KEY, 'm/d/Y', SettingScope::User, $this->owner->getKey(), $this->owner);

        expect($this->resolver->get(LAYERED_KEY, $other->getKey(), $this->company->getKey()))
            ->toBe(SettingsCatalogue::find(LAYERED_KEY)?->default);
    });

    it('does not apply one company’s override to another company', function (): void {
        $second = app(CompanyService::class)->create(
            new CreateCompanyData(name: 'Second Books'),
            $this->owner,
        );

        $this->settings->set(LAYERED_KEY, 'd M Y', SettingScope::Company, $this->company->getKey(), $this->owner);

        expect($this->resolver->get(LAYERED_KEY, null, $second->getKey()))
            ->toBe(SettingsCatalogue::find(LAYERED_KEY)?->default);
    });

    it('skips a scope whose target is unknown rather than treating it as no override', function (): void {
        $this->settings->set(LAYERED_KEY, 'd M Y', SettingScope::Company, $this->company->getKey(), $this->owner);
        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);

        // Resolved with no company in context — a scheduled job, or a request before a company is
        // selected. The company layer is skipped and the workspace value applies. Falling through
        // to the *shipped* default here would look correct and be wrong.
        expect($this->resolver->get(LAYERED_KEY, null, null))->toBe('Y-m-d');
    });

    it('only consults the scopes a setting is overridable at', function (): void {
        // `localisation.number_format` is company and workspace only — deliberately not personal,
        // because grouping belongs to the books rather than the reader.
        $definition = SettingsCatalogue::find('localisation.number_format');

        expect($definition?->isOverridableAt(SettingScope::User))->toBeFalse()
            ->and(array_map(
                static fn (SettingScope $scope): string => $scope->value,
                $definition?->resolutionScopes() ?? [],
            ))->not->toContain('user');
    });
});

describe('scope refusal', function (): void {
    it('refuses to set a setting at a scope it is not overridable at', function (): void {
        $exception = catchPlatformException(fn () => $this->settings->set(
            'localisation.number_format',
            'western',
            SettingScope::User,
            $this->owner->getKey(),
            $this->owner,
        ));

        expect($exception->problemCode())->toBe('setting-not-overridable-at-scope');
    });

    it('refuses an unknown key rather than storing it', function (): void {
        $exception = catchPlatformException(fn () => $this->settings->set(
            'localisation.not_a_real_setting',
            'x',
            SettingScope::Tenant,
            actor: $this->owner,
        ));

        // Storing it would produce a row nothing ever reads, which is indistinguishable from a
        // setting that does not work.
        expect($exception->problemCode())->toBe('unknown-setting');
    });

    it('refuses a scope that needs a target without one', function (): void {
        $exception = catchPlatformException(fn () => $this->settings->set(
            LAYERED_KEY,
            'Y-m-d',
            SettingScope::Company,
            null,
            $this->owner,
        ));

        expect($exception->problemCode())->toBe('setting-scope-target-required');
    });

    it('rejects a value outside the setting’s options', function (): void {
        $exception = catchPlatformException(fn () => $this->settings->set(
            LAYERED_KEY,
            'not-a-format',
            SettingScope::Tenant,
            actor: $this->owner,
        ));

        expect($exception->problemCode())->toBe('invalid-setting-value');
    });

    it('rejects a value outside the setting’s numeric range', function (): void {
        $exception = catchPlatformException(fn () => $this->settings->set(
            'localisation.week_starts_on',
            9,
            SettingScope::Tenant,
            actor: $this->owner,
        ));

        expect($exception->problemCode())->toBe('invalid-setting-value');
    });
});

describe('coercion', function (): void {
    it('stores an integer setting as an integer, not the string a form posted', function (): void {
        $this->settings->set('localisation.week_starts_on', '0', SettingScope::Tenant, actor: $this->owner);

        // Every value arrives from an HTML form as a string. A setting that resolves to "0" rather
        // than 0 is truthy, which inverts every boolean-ish check downstream.
        expect($this->resolver->get('localisation.week_starts_on'))->toBe(0);
    });

    it('stores a boolean setting as a boolean', function (): void {
        $this->settings->set('security.require_two_factor', 'true', SettingScope::Tenant, actor: $this->owner);

        expect($this->resolver->get('security.require_two_factor'))->toBeTrue();
    });
});

describe('resetting to inherited', function (): void {
    it('removes the override so the next layer applies again', function (): void {
        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);
        $this->settings->set(LAYERED_KEY, 'm/d/Y', SettingScope::User, $this->owner->getKey(), $this->owner);

        $this->settings->reset(LAYERED_KEY, SettingScope::User, $this->owner->getKey());

        // Reset is a delete, not a write of the inherited value: storing the inherited value would
        // freeze it, so a later workspace change would not reach this user.
        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe('Y-m-d')
            ->and(Setting::query()->atScope(SettingScope::User, $this->owner->getKey())->where('key', LAYERED_KEY)->exists())
            ->toBeFalse();
    });

    it('is harmless when there is no override to remove', function (): void {
        $this->settings->reset(LAYERED_KEY, SettingScope::User, $this->owner->getKey());

        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe(SettingsCatalogue::find(LAYERED_KEY)?->default);
    });
});

describe('cache invalidation', function (): void {
    it('reflects a new override immediately rather than serving the cached layer', function (): void {
        // Warms the per-scope cache and the per-request memo.
        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe(SettingsCatalogue::find(LAYERED_KEY)?->default);

        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);

        // A settings screen that saves and then shows the old value is indistinguishable from a
        // save that failed, and the user's next move is to save again.
        expect($this->resolver->get(LAYERED_KEY, $this->owner->getKey(), $this->company->getKey()))
            ->toBe('Y-m-d');
    });

    it('reflects a reset immediately', function (): void {
        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);
        expect($this->resolver->get(LAYERED_KEY))->toBe('Y-m-d');

        $this->settings->reset(LAYERED_KEY, SettingScope::Tenant);

        expect($this->resolver->get(LAYERED_KEY))->toBe(SettingsCatalogue::find(LAYERED_KEY)?->default);
    });

    it('does not let one workspace’s override leak into another', function (): void {
        $globex = $this->createWorkspace('globex');

        $this->settings->set(LAYERED_KEY, 'Y-m-d', SettingScope::Tenant, actor: $this->owner);

        $seenByGlobex = app(TenantContext::class)->runFor(
            $globex['tenant'],
            fn () => app(SettingsResolver::class)->get(LAYERED_KEY),
        );

        // The settings cache is prefixed per workspace by CacheTagBootstrapper. Without that, the
        // first workspace to read a key decides its value for everyone on that server.
        expect($seenByGlobex)->toBe(SettingsCatalogue::find(LAYERED_KEY)?->default);
    });
});

describe('writing a group at once', function (): void {
    it('applies every value in one transaction', function (): void {
        $this->settings->setMany([
            LAYERED_KEY => 'Y-m-d',
            'localisation.time_format' => 'h:i A',
        ], SettingScope::Tenant, actor: $this->owner);

        expect($this->resolver->get(LAYERED_KEY))->toBe('Y-m-d')
            ->and($this->resolver->get('localisation.time_format'))->toBe('h:i A');
    });

    it('applies none of them when one is invalid', function (): void {
        catchPlatformException(fn () => $this->settings->setMany([
            LAYERED_KEY => 'Y-m-d',
            'localisation.time_format' => 'not-a-time-format',
        ], SettingScope::Tenant, actor: $this->owner));

        // A settings form posts every field together. Half-applying it leaves the user looking at a
        // screen where some changes took and some did not, with no indication which.
        expect($this->resolver->get(LAYERED_KEY))->toBe(SettingsCatalogue::find(LAYERED_KEY)?->default);
    });
});

describe('the public subset', function (): void {
    it('exposes only settings marked public', function (): void {
        $public = $this->resolver->publicSettings($this->owner->getKey(), $this->company->getKey());

        $nonPublic = array_values(array_filter(
            SettingsCatalogue::all(),
            static fn ($definition): bool => ! $definition->public,
        ));

        // The SPA bootstrap ships this to the browser. A setting that is not public must not appear
        // there — `security.password_expiry_days` tells an attacker how long a stolen password lasts.
        foreach ($nonPublic as $definition) {
            expect($public)->not->toHaveKey($definition->key);
        }

        expect($public)->toHaveKey(LAYERED_KEY);
    });
});
