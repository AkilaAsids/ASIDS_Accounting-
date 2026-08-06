<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sign-in credentials.
 *
 * No `Password::defaults()` here on purpose: applying the complexity rules to a *login*
 * would tell an attacker which submitted passwords could not possibly be correct, and would
 * lock out any user whose password predates a policy tightening.
 */
final class LoginRequest extends FormRequest
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
        return [
            // `email:rfc` only — a DNS check on sign-in would add a network round trip to the
            // most latency-sensitive request in the product, and fail during a DNS blip.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }
}
