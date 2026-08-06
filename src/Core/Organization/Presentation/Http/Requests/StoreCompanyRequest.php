<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Requests;

use Asids\Core\Organization\Domain\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            // Uniqueness is settled by the service, which appends a suffix rather than
            // rejecting: two group companies often derive the same initials, and forcing the
            // customer to invent a code is friction for no safety gain.
            'code' => ['nullable', 'string', 'min:2', 'max:24', 'regex:/^[A-Za-z0-9-]+$/'],

            'base_currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'currency_precision' => ['nullable', 'integer', 'min:0', 'max:6'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            // Capped at 28 to match the database check constraint: a fiscal year starting on
            // the 29th would be undefined in February.
            'fiscal_year_start_day' => ['nullable', 'integer', 'min:1', 'max:28'],

            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'timezone' => ['required', 'string', 'timezone'],
            'locale' => ['nullable', 'string', Rule::in(config('asids.regional.supported_locales', ['en']))],

            'registration_number' => ['nullable', 'string', 'max:64'],
            'tax_identification_number' => ['nullable', 'string', 'max:64'],
            'vat_registration_number' => ['nullable', 'string', 'max:64'],
            // SVAT presupposes VAT, mirroring the database constraint so the caller gets a
            // field-level message instead of a domain error.
            'svat_registration_number' => ['nullable', 'string', 'max:64', 'required_with:is_svat_registered'],

            'business_type' => ['nullable', 'string', 'max:48'],
            'industry' => ['nullable', 'string', 'max:96'],
            'established_on' => ['nullable', 'date', 'before_or_equal:today'],

            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:96'],
            'district' => ['nullable', 'string', 'max:96'],
            'postal_code' => ['nullable', 'string', 'max:24'],

            'primary_branch_name' => ['nullable', 'string', 'max:255'],
            'primary_branch_code' => ['nullable', 'string', 'max:24', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fiscal_year_start_day.max' => 'The fiscal year start day cannot be later than the 28th, so the calendar is defined in every month.',
            'svat_registration_number.required_with' => 'An SVAT registration number is required when SVAT registration is declared.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => $this->filled('code') ? strtoupper(trim((string) $this->input('code'))) : null,
            'base_currency_code' => $this->filled('base_currency_code') ? strtoupper(trim((string) $this->input('base_currency_code'))) : null,
            'country_code' => $this->filled('country_code') ? strtoupper(trim((string) $this->input('country_code'))) : null,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
