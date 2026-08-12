<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Requests;

use Asids\Core\Platform\Support\Validation\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Updating a customer.
 *
 * Every field is `sometimes`, so `$request->validated()` passes straight through as the
 * attribute array `CustomerService::update()` consumes (design §3.1, §4.2): key absent = leave
 * untouched, key present with `null` = clear. `branch_id`, `receivable_account_id` and
 * `credit_limit` are the ones I3 exists for — each is independently clearable by sending
 * `null`, and independently left alone by omitting the key — the `UpdateAccountRequest` /
 * `UpdateTaxCodeRequest` pattern.
 *
 * `name`, `code`, `payment_terms_days` and `is_vat_registered` are never nullable: none of them
 * is a column a customer can be left without, and a code is never cleared (only ever replaced,
 * and only while nothing has been invoiced — `CustomerService::update()` enforces that).
 */
final class UpdateCustomerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'code' => ['sometimes', 'string', 'min:1', 'max:32'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_identification_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vat_registration_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_vat_registered' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'nullable', ...EmailAddress::syntax(), 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:96'],
            'district' => ['sometimes', 'nullable', 'string', 'max:96'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'credit_limit' => ['sometimes', 'nullable', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'],
            'receivable_account_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->string('code')->toString())]);
        }

        $creditLimit = $this->input('credit_limit');

        if (is_numeric($creditLimit) && ! is_string($creditLimit)) {
            $this->merge(['credit_limit' => (string) $creditLimit]);
        }
    }
}
