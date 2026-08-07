<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Application\Services;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Models\Setting;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Writes settings.
 *
 * Two rules make this a service rather than a thin wrapper over `updateOrCreate`:
 *
 *   * A value must be valid for its definition. The catalogue owns the rules, so an invalid value
 *     is rejected here rather than discovered by whatever code reads it later — a malformed date
 *     format string would otherwise break every rendered date in the workspace.
 *
 *   * Writing at a scope the definition does not permit is refused. Without that check a
 *     per-user override of `security.require_two_factor` would let any user opt out of the control
 *     their administrator mandated.
 */
final readonly class SettingsService
{
    public function __construct(
        private SettingsResolver $resolver,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Write one override.
     */
    public function set(
        string $key,
        mixed $value,
        SettingScope $scope,
        ?string $scopeId = null,
        ?User $actor = null,
    ): Setting {
        $definition = SettingsCatalogue::find($key);

        if ($definition === null) {
            throw BusinessRuleViolation::make(
                code: 'unknown-setting',
                message: 'That setting does not exist.',
                context: ['key' => $key],
            );
        }

        if (! $definition->isOverridableAt($scope)) {
            throw BusinessRuleViolation::make(
                code: 'setting-not-overridable-at-scope',
                message: sprintf('“%s” cannot be set at the %s level.', $definition->label, $scope->label()),
                context: [
                    'key' => $key,
                    'scope' => $scope->value,
                    'overridable_at' => array_map(static fn (SettingScope $s): string => $s->value, $definition->overridableAt),
                ],
            );
        }

        if ($scope->requiresTarget() && $scopeId === null) {
            throw BusinessRuleViolation::make(
                code: 'setting-scope-target-required',
                message: sprintf('A %s must be identified to set this value.', strtolower($scope->label())),
            );
        }

        $coerced = $definition->type->coerce($value);
        $this->validate($definition->key, $definition->label, $coerced, $definition->validationRules(), $definition->options);

        $setting = DB::transaction(function () use ($definition, $coerced, $scope, $scopeId, $actor): Setting {
            $setting = Setting::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $scope === SettingScope::System ? null : $this->tenantContext->id())
                ->atScope($scope, $scopeId)
                ->where('key', $definition->key)
                ->first() ?? new Setting;

            $setting->fill([
                'tenant_id' => $scope === SettingScope::System ? null : $this->tenantContext->require()->getKey(),
                'scope' => $scope,
                'scope_id' => $scopeId,
                'key' => $definition->key,
                'type' => $definition->type,
                'value' => $definition->encrypted && is_string($coerced)
                    ? Crypt::encryptString($coerced)
                    : $coerced,
                'is_encrypted' => $definition->encrypted,
                'updated_by_id' => $actor?->getKey(),
            ]);

            $setting->save();

            return $setting;
        });

        // Invalidated after the transaction commits, so a concurrent reader cannot repopulate the
        // cache from the pre-commit state.
        $this->resolver->forget($scope, $scopeId);

        return $setting;
    }

    /**
     * Write several overrides at one scope, atomically.
     *
     * A settings form submits a whole group, and applying it partially would leave the workspace in
     * a state the administrator never chose — half a security policy is worse than none.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the resolved values after writing
     */
    public function setMany(array $values, SettingScope $scope, ?string $scopeId = null, ?User $actor = null): array
    {
        DB::transaction(function () use ($values, $scope, $scopeId, $actor): void {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $scope, $scopeId, $actor);
            }
        });

        $this->resolver->forget($scope, $scopeId);

        $resolved = [];

        foreach (array_keys($values) as $key) {
            $resolved[$key] = $this->resolver->get(
                key: $key,
                userId: $scope === SettingScope::User ? $scopeId : null,
                companyId: $scope === SettingScope::Company ? $scopeId : null,
            );
        }

        return $resolved;
    }

    /**
     * Remove an override so the value inherits again.
     *
     * A distinct operation from setting the default value explicitly: an override equal to today's
     * default would silently stop tracking a change to that default in a later release.
     */
    public function reset(string $key, SettingScope $scope, ?string $scopeId = null): void
    {
        Setting::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $scope === SettingScope::System ? null : $this->tenantContext->id())
            ->atScope($scope, $scopeId)
            ->where('key', $key)
            ->delete();

        $this->resolver->forget($scope, $scopeId);
    }

    /**
     * Drop every override belonging to a user or company. Called when one is deleted, since the
     * `settings.scope_id` column has no foreign key — the target table varies.
     */
    public function purgeScope(SettingScope $scope, string $scopeId): void
    {
        Setting::query()
            ->withoutGlobalScopes()
            ->atScope($scope, $scopeId)
            ->delete();

        $this->resolver->forget($scope, $scopeId);
    }

    /**
     * @param  list<string>  $rules
     * @param  array<int|string, string>|null  $options
     */
    private function validate(string $key, string $label, mixed $value, array $rules, ?array $options): void
    {
        // A select's allowed values come from the catalogue rather than being repeated as an `in:`
        // rule, so the control and its validation cannot disagree.
        if ($options !== null && $value !== null && ! array_key_exists((string) $value, $options)) {
            throw BusinessRuleViolation::make(
                code: 'invalid-setting-value',
                message: sprintf('“%s” is not an accepted value for %s.', (string) $value, $label),
                context: ['key' => $key, 'accepted' => array_keys($options)],
            );
        }

        $validator = Validator::make(
            data: ['value' => $value],
            rules: ['value' => $rules],
            attributes: ['value' => $label],
        );

        if ($validator->fails()) {
            throw BusinessRuleViolation::make(
                code: 'invalid-setting-value',
                message: (string) $validator->errors()->first('value'),
                context: ['key' => $key],
            );
        }
    }
}
