<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ending a tax code's current effective range, so a successor rate can take over the next day.
 *
 * `last_effective_day` is the only field: whether it falls before the range even starts is a
 * business rule (`TaxCodeService::endRange()` — `range-ends-before-it-starts`), not a shape the
 * request can check.
 */
final class EndTaxCodeRangeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'last_effective_day' => ['required', 'date'],
        ];
    }
}
