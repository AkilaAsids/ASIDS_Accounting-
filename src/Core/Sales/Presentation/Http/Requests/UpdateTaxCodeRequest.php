<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Requests;

use Asids\Core\Sales\Domain\Enums\TaxType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updating a tax code.
 *
 * Every field is `sometimes`, so `$request->validated()` passes straight through as the attribute
 * array `TaxCodeService::update()` consumes (design §5.2): key absent = leave alone, key present
 * with `null` = clear. `effective_to: null` is how a closed range is reopened — the one place the
 * distinction matters, same as `ChartOfAccountsService::update()` and the reason `update()` takes
 * an array rather than a DTO at all.
 *
 * `code`, `name`, `tax_type`, `rate`, `effective_from` and `is_active` are never nullable: none of
 * them is a column a tax code can be left without. `effective_to`, `output_account_id`,
 * `input_account_id` and `notes` stay clearable.
 *
 * `code`'s rule drops `min:1` for the same reason `StoreTaxCodeRequest` drops `required`/`min:1`:
 * the platform's global `TrimStrings` + `ConvertEmptyStringsToNull` middleware turns a
 * whitespace-only code into `null` before validation runs. Here that would collide with `code`
 * being "present but not a value that changes anything" versus "present and blank" — so
 * `prepareForValidation()` only restores the empty string when the key was actually sent
 * (`$this->has()`, which still sees the key even once its value has been nulled), leaving a
 * genuinely omitted `code` alone so the attribute-array "leave untouched" semantics survive.
 */
final class UpdateTaxCodeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'tax_type' => ['sometimes', 'string', Rule::in(TaxType::values())],
            'rate' => ['sometimes', 'string', 'regex:/^-?\d{1,3}(\.\d{1,4})?$/'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'output_account_id' => ['sometimes', 'nullable', 'uuid'],
            'input_account_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && ! is_string($this->input('code'))) {
            $this->merge(['code' => '']);
        }

        $rate = $this->input('rate');

        if (is_numeric($rate) && ! is_string($rate)) {
            $this->merge(['rate' => (string) $rate]);
        }
    }
}
