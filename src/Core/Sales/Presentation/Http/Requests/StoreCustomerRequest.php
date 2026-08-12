<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Requests;

use Asids\Core\Platform\Support\Validation\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a customer.
 *
 * `status`, `archived_at`, `tenant_id`, `company_id` and `created_by_id` are deliberately not
 * accepted — a customer's state moves only through the named action endpoints (archive,
 * deactivate, …), never a settable field, the same reasoning `StoreAccountRequest` uses to
 * refuse `normal_balance`.
 *
 * `credit_limit`'s regex lets a leading minus through, wider than a valid limit ever is
 * (design §4.2): a shape the service actually rejects (negative) reaches
 * `CustomerService`'s `resolveCreditLimit()` and comes back naming the real problem —
 * `negative-credit-limit` — rather than a generic validation failure. Only a genuinely
 * non-numeric limit is refused here, which shadows the service's own `credit-limit-not-a-number`
 * check as intended defense-in-depth.
 *
 * `branch_id` / `receivable_account_id` ownership, type and postability are all checked by the
 * service, the same reasoning `StoreAccountRequest` uses for `parent_id`, so an unsuitable value
 * produces the accurate hierarchy error rather than a generic "invalid".
 */
final class StoreCustomerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'min:1', 'max:32'],
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
