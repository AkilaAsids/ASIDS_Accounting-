<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing a draft invoice.
 *
 * Every field is `sometimes`, so `$request->validated()` passes straight through as the attribute array
 * `SalesInvoiceService::updateDraft()` consumes: key absent = leave untouched, key present with `null` = clear.
 * That distinction is load-bearing on an invoice — `reference`, `branch_id` and the header `discount_amount` are
 * each independently clearable, and a signature that could not express clearing would make all three permanent
 * once set. It is the reason ADR 0008 D1 moved customers to the same shape, and the contract
 * `UpdateCustomerRequest`, `UpdateTaxCodeRequest` and `UpdateAccountRequest` all follow.
 *
 * `due_date` is nullable here with a meaning of its own: cleared, the service re-derives it from the customer's
 * payment terms rather than writing null into a column that is not nullable. "Use the default" is the only
 * sensible reading of a cleared due date.
 *
 * `lines` is all-or-nothing. Supplying it replaces every line, because an invoice is a document rather than a
 * collection that accretes — matching submitted rows against stored ones by position is how a reordered line
 * silently becomes an edit of a different account. Omitting it leaves the lines alone, and the service still
 * recomputes the totals, because a changed `invoice_date` can change which tax rate applies even when no line
 * moved.
 *
 * Whether the invoice may be changed at all is not asked here. `SalesInvoiceService::updateDraft()` refuses
 * anything that is not a draft with `invoice-not-editable`, and the Milestone 5 immutability trigger refuses the
 * write underneath that. A status check in this class would be a third copy of one rule.
 *
 * No computed or record-owned field is accepted — see `StoreSalesInvoiceRequest` for the list and the reasoning.
 * `issue` is absent too: issuing is its own endpoint held by its own capability, not something a PUT may switch
 * on, following `JournalEntryController`'s split of posting from updating.
 */
final class UpdateSalesInvoiceRequest extends FormRequest
{
    private const string DECIMAL = 'regex:/^-?\d{1,15}(\.\d{1,4})?$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Not nullable: an invoice always has a customer and a date. Replaceable, never clearable.
            'customer_id' => ['sometimes', 'uuid'],
            'invoice_date' => ['sometimes', 'date'],
            // Nullable, and null means "re-derive from the customer's terms" rather than "store null".
            'due_date' => ['sometimes', 'nullable', 'date'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'discount_amount' => ['sometimes', 'nullable', 'string', self::DECIMAL],

            // Present replaces every line; absent leaves them. Still at least one when supplied — an invoice
            // cannot be emptied of lines and remain an invoice, and the service refuses that too.
            'lines' => ['sometimes', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required_with:lines', 'string', 'min:1', 'max:255'],
            'lines.*.quantity' => ['required_with:lines', 'string', self::DECIMAL],
            'lines.*.unit_price' => ['required_with:lines', 'string', self::DECIMAL],
            'lines.*.revenue_account_id' => ['required_with:lines', 'uuid'],
            'lines.*.tax_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'lines.*.discount_percent' => ['sometimes', 'nullable', 'string', self::DECIMAL],
            'lines.*.discount_amount' => ['sometimes', 'nullable', 'string', self::DECIMAL],
            'lines.*.branch_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'An invoice needs at least one line.',
            'discount_amount.regex' => 'Amounts may have at most four decimal places.',
            'lines.*.quantity.regex' => 'Quantities may have at most four decimal places.',
            'lines.*.unit_price.regex' => 'Amounts may have at most four decimal places.',
            'lines.*.discount_percent.regex' => 'Percentages may have at most four decimal places.',
            'lines.*.discount_amount.regex' => 'Amounts may have at most four decimal places.',
        ];
    }

    /**
     * As `StoreSalesInvoiceRequest`: a submitted number is stringified before the regex sees it.
     */
    protected function prepareForValidation(): void
    {
        if (is_numeric($this->input('discount_amount')) && ! is_string($this->input('discount_amount'))) {
            $this->merge(['discount_amount' => (string) $this->input('discount_amount')]);
        }

        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $this->merge([
            'lines' => array_map(static function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['quantity', 'unit_price', 'discount_percent', 'discount_amount'] as $field) {
                    if (isset($line[$field]) && is_numeric($line[$field]) && ! is_string($line[$field])) {
                        $line[$field] = (string) $line[$field];
                    }
                }

                return $line;
            }, $lines),
        ]);
    }
}
