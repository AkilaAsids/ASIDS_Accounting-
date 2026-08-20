<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Drafting an invoice, and optionally issuing it in the same call.
 *
 * Shape and type only. Ownership, account type, postability, tax effectiveness, the totals, the fiscal period and
 * every other domain rule belong to `SalesInvoiceService`, which produces an accurate problem code for each —
 * `customer-outside-company`, `revenue-account-not-postable`, `tax-rate-date-not-covered` and the rest. Repeating
 * any of them here would trade a precise refusal for a generic "invalid", the reasoning `StoreCustomerRequest`
 * sets out for `branch_id` and `receivable_account_id`.
 *
 * AMOUNTS ARE VALIDATED AS DECIMAL STRINGS
 * ----------------------------------------
 * Not as numbers. A JSON number arrives in PHP as a float, and `10.005` is not exactly representable — accepting
 * one would put an approximation into an invoice before any of the module's exact arithmetic ran. The regex is
 * where that is refused, at four decimal places to match the columns: rejected rather than rounded, because
 * silently dropping a digit from a submitted amount is how a total stops matching the document it came from.
 *
 * `lines` requires at least **one**, not two. An invoice with a single line is an ordinary document; the
 * two-line minimum on `StoreJournalEntryRequest` exists because an entry needs something debited *and* something
 * credited, which has no analogue here.
 *
 * `tax_code` IS A CODE, NEVER AN ID
 * --------------------------------
 * Which rate applies depends on the invoice date, and only company + code + date identifies the right
 * effective-dated row. Accepting an id would let a caller name an expired or future rate directly and bypass
 * `TaxRateResolver`, the only thing that knows which row is correct for the document being written. Validated as a
 * short string for that reason, and it is not a mistake that `uuid` is absent.
 *
 * `discount_amount` and `discount_percent` are both accepted per line and the pair is **not** checked here: the
 * service refuses a line carrying both with `invoice-line-two-discounts`, which says what is actually wrong.
 *
 * Nothing computed or owned by the record is accepted — no `status`, `number`, `total`, `subtotal`, `tax_total`,
 * `discount_total`, `amount_paid`, `amount_due`, `currency_code`, `exchange_rate`, `company_id`, `tenant_id`,
 * `journal_entry_id`, `issued_*`, `cancelled_*` or `created_by_id`. `SalesInvoice::$fillable` is three free-text
 * fields and `SalesInvoiceLine::$fillable` is empty, so a caller sending one would be ignored rather than
 * obeyed; refusing it at the boundary keeps the API's contract honest about what it reads.
 */
final class StoreSalesInvoiceRequest extends FormRequest
{
    /**
     * Four decimal places, matching every monetary column in the module.
     */
    private const string DECIMAL = 'regex:/^-?\d{1,15}(\.\d{1,4})?$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid'],
            'invoice_date' => ['required', 'date'],
            // Absent means "derive it": the service falls back to the customer's payment terms, which is exactly
            // what the customer record exists to hold.
            'due_date' => ['sometimes', 'nullable', 'date'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // A header discount, allocated across the lines in proportion to their subtotals by the service.
            'discount_amount' => ['sometimes', 'nullable', 'string', self::DECIMAL],

            'lines' => ['required', 'array', 'min:1', 'max:500'],
            // Capped at the width of `sales_invoice_lines.description`, and the ledger line it is copied to.
            'lines.*.description' => ['required', 'string', 'min:1', 'max:255'],
            // May be negative for a correction, never zero — the service refuses zero with
            // `invoice-line-zero-quantity`, which explains why rather than just refusing.
            'lines.*.quantity' => ['required', 'string', self::DECIMAL],
            'lines.*.unit_price' => ['required', 'string', self::DECIMAL],
            'lines.*.revenue_account_id' => ['required', 'uuid'],
            'lines.*.tax_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'lines.*.discount_percent' => ['sometimes', 'nullable', 'string', self::DECIMAL],
            'lines.*.discount_amount' => ['sometimes', 'nullable', 'string', self::DECIMAL],
            'lines.*.branch_id' => ['sometimes', 'nullable', 'uuid'],

            // Draft and issue in one call. Absent or false leaves a draft, which is the bookkeeper path; the
            // policy decides whether this caller may issue at all, and the controller asks it inside the
            // transaction so a refusal leaves no invoice behind.
            'issue' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'An invoice needs at least one line.',
            'lines.min' => 'An invoice needs at least one line.',
            'discount_amount.regex' => 'Amounts may have at most four decimal places.',
            'lines.*.quantity.regex' => 'Quantities may have at most four decimal places.',
            'lines.*.unit_price.regex' => 'Amounts may have at most four decimal places.',
            'lines.*.discount_percent.regex' => 'Percentages may have at most four decimal places.',
            'lines.*.discount_amount.regex' => 'Amounts may have at most four decimal places.',
        ];
    }

    /**
     * Numbers coerced to strings before the regex sees them.
     *
     * So a client sending `100.5` rather than `"100.5"` is accepted rather than told its amount is not a string.
     * The float has already happened by then for a value like `10.005` — which is why the API documents strings —
     * but refusing the common case would be pedantry. Exactly what `StoreJournalEntryRequest` does for its debit
     * and credit columns.
     */
    protected function prepareForValidation(): void
    {
        $decimal = static function (mixed $value): mixed {
            return is_numeric($value) && ! is_string($value) ? (string) $value : $value;
        };

        if (is_numeric($this->input('discount_amount')) && ! is_string($this->input('discount_amount'))) {
            $this->merge(['discount_amount' => (string) $this->input('discount_amount')]);
        }

        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $this->merge([
            'lines' => array_map(static function (mixed $line) use ($decimal): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['quantity', 'unit_price', 'discount_percent', 'discount_amount'] as $field) {
                    if (isset($line[$field])) {
                        $line[$field] = $decimal($line[$field]);
                    }
                }

                return $line;
            }, $lines),
        ]);
    }
}
