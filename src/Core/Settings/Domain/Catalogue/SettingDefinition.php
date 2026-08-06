<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Domain\Catalogue;

use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Enums\SettingType;

/**
 * One configurable value: its key, type, default, and the scopes at which it may be overridden.
 *
 * Definitions live in code for the same reason permissions do — a setting row without code that
 * reads it is a control that does nothing, and code without a definition silently uses a hardcoded
 * default. Making code authoritative means a setting arrives and departs with the feature that
 * consumes it.
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<SettingScope>  $overridableAt  Scopes at which a value may be set, most specific first.
     * @param  list<string>  $rules  Extra validation rules beyond the type's own.
     * @param  array<int|string, string>|null  $options  Allowed values for a select control.
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default,
        public string $label,
        public string $description,
        public string $group,
        public array $overridableAt = [SettingScope::Tenant],
        public array $rules = [],
        public ?array $options = null,
        /**
         * Whether the value may be read by a user without the settings-view permission. Branding
         * and locale must be readable by everyone, or the interface cannot render.
         */
        public bool $public = false,
        /**
         * Encrypted at rest. For third-party credentials, which will arrive with the integrations
         * phase; the resolver handles them transparently.
         */
        public bool $encrypted = false,
        public int $sortOrder = 0,
    ) {}

    public function isOverridableAt(SettingScope $scope): bool
    {
        return in_array($scope, $this->overridableAt, true);
    }

    /**
     * Scopes to consult when resolving, narrowed to those this setting actually permits, so the
     * resolver never issues a lookup that cannot produce a value.
     *
     * @return list<SettingScope>
     */
    public function resolutionScopes(): array
    {
        return array_values(array_filter(
            SettingScope::resolutionOrder(),
            fn (SettingScope $scope): bool => $this->isOverridableAt($scope),
        ));
    }

    /**
     * @return list<string>
     */
    public function validationRules(): array
    {
        return [...$this->type->validationRules(), ...$this->rules];
    }
}
