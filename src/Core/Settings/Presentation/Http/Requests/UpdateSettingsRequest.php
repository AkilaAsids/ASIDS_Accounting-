<?php

declare(strict_types=1);

namespace Asids\Core\Settings\Presentation\Http\Requests;

use Asids\Core\Settings\Domain\Catalogue\SettingsCatalogue;
use Asids\Core\Settings\Domain\Enums\SettingScope;
use Asids\Core\Settings\Domain\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a group of settings at one scope.
 *
 * Per-value validation is deliberately NOT duplicated here — it lives on the definition, and
 * SettingsService applies it. This request validates only the shape: a known scope, a known target,
 * and keys that exist in the catalogue. Repeating the rules would give two places to change when a
 * setting's constraints move, and they would drift.
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scope = SettingScope::tryFrom((string) $this->input('scope', ''));

        return $scope !== null
            && ($this->user()?->can('updateAtScope', [Setting::class, $scope]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::in(['user', 'company', 'tenant'])],
            // Required for user and company scope, forbidden for workspace scope — the database has
            // a check constraint asserting the same thing.
            'scope_id' => ['nullable', 'uuid', 'required_if:scope,company', 'prohibited_if:scope,tenant'],

            'settings' => ['required', 'array', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scope_id.required_if' => 'A company must be identified when saving company settings.',
            'scope_id.prohibited_if' => 'Workspace settings apply to the whole workspace and take no target.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            /** @var array<string, mixed> $settings */
            $settings = $this->input('settings', []);
            $known = SettingsCatalogue::keyed();

            foreach (array_keys($settings) as $key) {
                if (! is_string($key) || ! array_key_exists($key, $known)) {
                    // Rejected rather than ignored: a client sending an unknown key believes it is
                    // saving something, and silently discarding it produces a support ticket about
                    // a setting that "does not stick".
                    $validator->errors()->add("settings.{$key}", 'That setting does not exist.');
                }
            }
        });
    }

    public function scopeEnum(): SettingScope
    {
        return SettingScope::from((string) $this->validated('scope'));
    }

    /**
     * The scope target, defaulted to the current user for personal settings so a client never has
     * to send its own id — and, more importantly, so it cannot send someone else's.
     */
    public function scopeTarget(): ?string
    {
        $scope = $this->scopeEnum();

        if ($scope === SettingScope::User) {
            return (string) $this->user()?->getKey();
        }

        $id = $this->validated('scope_id');

        return is_string($id) ? $id : null;
    }
}
