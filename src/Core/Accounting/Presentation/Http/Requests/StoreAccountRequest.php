<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Requests;

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating an account.
 *
 * `normal_balance` is deliberately not accepted. It is a function of the type, and offering it would
 * let a caller create an account that reports every figure with the wrong sign while the books still
 * balance. `is_system` and `system_key` are likewise absent: those are the platform's, and a request
 * that could set them could hijack the year-end close.
 */
final class StoreAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:1', 'max:32'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', Rule::in(AccountType::values())],
            // Existence, company ownership and type compatibility are all checked by the service, so
            // an unsuitable parent produces the accurate hierarchy error rather than a generic
            // "invalid" that does not say what is wrong with it.
            'parent_id' => ['nullable', 'uuid'],
            'is_postable' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->string('code')->toString())]);
        }
    }
}
