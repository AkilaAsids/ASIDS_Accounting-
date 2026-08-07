<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Presentation\Http\Requests;

use Asids\Core\Platform\Support\Validation\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Public workspace sign-up.
 *
 * Authorisation is open because this is the front door — but the endpoint is
 * throttled hard (`throttle:login`) and the slug rules below are the same shape the
 * database check constraint enforces, so nothing malformed reaches provisioning.
 */
final class RegisterTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var list<string> $reserved */
        $reserved = config('asids.tenancy.reserved_slugs', []);

        return [
            'tenant_name' => ['required', 'string', 'min:2', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],

            'slug' => [
                'required',
                'string',
                'min:3',
                'max:63',
                // The DNS label shape. Uniqueness is checked by the service, not
                // here, so that the "taken" answer is a 409 with a suggestion
                // rather than a validation error indistinguishable from a typo.
                'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/',
                Rule::notIn($reserved),
            ],

            'owner_first_name' => ['required', 'string', 'max:100'],
            'owner_last_name' => ['nullable', 'string', 'max:100'],
            'owner_email' => ['required', ...EmailAddress::deliverable(), 'max:255'],
            'owner_password' => ['required', 'string', 'confirmed', Password::defaults()],

            'company_name' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'currency_code' => ['nullable', 'string', 'size:3', 'alpha'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'locale' => ['nullable', 'string', Rule::in(config('asids.regional.supported_locales', ['en']))],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'tax_identification_number' => ['nullable', 'string', 'max:64'],

            'accepts_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The workspace address may contain only lowercase letters, numbers and hyphens.',
            'slug.not_in' => 'That workspace address is reserved. Please choose another.',
            'accepts_terms.accepted' => 'You must accept the terms of service to create a workspace.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => strtolower(trim((string) $this->input('slug'))),
            'owner_email' => strtolower(trim((string) $this->input('owner_email'))),
            'country_code' => strtoupper((string) $this->input('country_code', config('asids.regional.default_country'))),
            'currency_code' => strtoupper((string) $this->input('currency_code', config('asids.regional.default_currency'))),
        ]);
    }
}
