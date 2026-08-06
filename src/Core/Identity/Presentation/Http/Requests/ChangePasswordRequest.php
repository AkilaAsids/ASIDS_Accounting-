<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Verified by the controller against the hash, not by the `current_password` rule:
            // that rule uses the default guard, and this application's guard resolution depends
            // on tenant context that the validator does not carry.
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.different' => 'Your new password must be different from your current one.',
        ];
    }
}
