<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Drafting or posting a journal entry.
 *
 * Amounts are validated as **decimal strings**, not numbers. A JSON number arrives as a float in
 * PHP, and `10.005` is not exactly representable — accepting one would put an approximation into the
 * ledger before any of the module's careful arithmetic got a chance to run. The regex is the boundary
 * where that is refused.
 *
 * Balance is not checked here. It belongs to the posting service, which can say by how much and on
 * which side, and to the deferred constraint trigger, which catches everything that does not come
 * through either.
 */
final class StoreJournalEntryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'min:1', 'max:255'],
            'reference' => ['nullable', 'string', 'max:120'],
            'journal_id' => ['nullable', 'uuid'],

            // At least two: a single line cannot balance against anything. The service says so too,
            // with a message naming the actual problem.
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.branch_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],

            // Up to four decimal places, matching the column. Rejected rather than rounded, because
            // silently dropping a digit from a submitted amount is how a total stops matching the
            // document it came from.
            'lines.*.debit' => ['nullable', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'],
            'lines.*.credit' => ['nullable', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'],

            // Whether to post immediately. Absent or false leaves a draft, which is what the
            // bookkeeper path wants; the policy decides whether the caller may post at all.
            'post' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'An entry needs at least two lines: something debited and something credited.',
            'lines.*.debit.regex' => 'Amounts may have at most four decimal places.',
            'lines.*.credit.regex' => 'Amounts may have at most four decimal places.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->input('lines', []);

        if (! is_array($lines)) {
            return;
        }

        // Numbers coerced to strings before the regex sees them, so a client sending `100.5` rather
        // than `"100.5"` is accepted rather than told its amount is not a string. The float has
        // already happened by then for a value like 10.005 — which is why the API documents strings —
        // but rejecting the common case would be pedantry.
        $this->merge([
            'lines' => array_map(static function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['debit', 'credit'] as $side) {
                    if (isset($line[$side]) && is_numeric($line[$side])) {
                        $line[$side] = (string) $line[$side];
                    }
                }

                return $line;
            }, $lines),
        ]);
    }
}
