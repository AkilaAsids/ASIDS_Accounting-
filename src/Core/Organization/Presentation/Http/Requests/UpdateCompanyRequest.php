<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Presentation\Http\Requests;

use Asids\Core\Organization\Domain\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Company edit.
 *
 * `code` and `slug` are absent by design: both appear on posted document numbers and in
 * URLs, so they are fixed at creation. `base_currency_code` and the fiscal calendar *are*
 * accepted, because they remain editable until the books have activity — the service decides
 * which side of that line the company is on.
 */
final class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('update', $company) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],

            'base_currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'currency_precision' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'fiscal_year_start_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'fiscal_year_start_day' => ['sometimes', 'integer', 'min:1', 'max:28'],

            'country_code' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', 'string', Rule::in(config('asids.regional.supported_locales', ['en']))],

            'registration_number' => ['nullable', 'string', 'max:64'],
            'tax_identification_number' => ['nullable', 'string', 'max:64'],
            'vat_registration_number' => ['nullable', 'string', 'max:64'],
            'svat_registration_number' => ['nullable', 'string', 'max:64'],

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
        ];
    }
}
