<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Asids\Core\Platform\Support\Validation\EmailAddress;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Deliberately no `exists` rule: a validation error for an unknown address would
        // confirm which addresses hold accounts, which is the enumeration leak the controller
        // goes out of its way to avoid.
        return [
            'email' => ['required', ...EmailAddress::syntax(), 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
