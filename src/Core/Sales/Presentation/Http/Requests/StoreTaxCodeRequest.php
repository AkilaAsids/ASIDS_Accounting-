<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Requests;

use Asids\Core\Sales\Domain\Enums\TaxType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a tax code.
 *
 * `rate`'s regex lets a leading minus and up to three whole digits through — wider than a valid
 * rate ever is — so a shape the service actually rejects (negative, over one hundred, non-zero on
 * an exempt code) reaches `TaxCodeService::create()` and comes back naming the real problem,
 * rather than a generic validation failure. Only a genuinely non-numeric rate is refused here
 * (design §5.2, §5.4): that shadows `tax-rate-not-a-number` from the service, which is intended
 * defense-in-depth rather than a gap.
 *
 * The sign/range/zero-rate-type/effective-range/output-account rules all stay with the service —
 * `TaxCodeService::assertRate()`, `assertRange()`, `resolveOutputAccountId()` — the same reasoning
 * `StoreAccountRequest` uses for `parent_id`.
 *
 * `code` is deliberately **not required or `min:1` here**, unlike `StoreAccountRequest`. The
 * platform's global `TrimStrings` + `ConvertEmptyStringsToNull` middleware (`bootstrap/app.php`)
 * turns a whitespace-only — or entirely missing — code into `null` before this request's own
 * validation ever runs, so `required`/`min:1` cannot tell "blank" from "never sent" and would
 * both fail as a generic validation error. `prepareForValidation()` restores it to an empty
 * string instead, so it reaches `TaxCodeService::assertCodeShape()` and comes back as the
 * intended `tax-code-blank` domain refusal. `TaxCodeData::fromArray()` and the service both trim
 * again on the way in, so a genuinely padded code (`' VAT '`) still ends up clean.
 */
final class StoreTaxCodeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['string', 'max:32'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'tax_type' => ['required', 'string', Rule::in(TaxType::values())],
            'rate' => ['required', 'string', 'regex:/^-?\d{1,3}(\.\d{1,4})?$/'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'output_account_id' => ['sometimes', 'nullable', 'uuid'],
            'input_account_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('code'))) {
            $this->merge(['code' => '']);
        }

        $rate = $this->input('rate');

        if (is_numeric($rate) && ! is_string($rate)) {
            $this->merge(['rate' => (string) $rate]);
        }
    }
}
