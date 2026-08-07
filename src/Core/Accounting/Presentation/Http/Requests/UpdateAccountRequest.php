<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Requests;

use Asids\Core\Accounting\Domain\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updating an account.
 *
 * Every field is `sometimes`, so a client may send only what changed. That matters here more than
 * usual: `type` is refused outright once the account has postings, and a form that posted every field
 * back would trip that rule for an edit that never touched the type.
 */
final class UpdateAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'min:1', 'max:32'],
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'type' => ['sometimes', 'string', Rule::in(AccountType::values())],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
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
