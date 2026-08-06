<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Self-service profile edit.
 *
 * Narrower than the administrative form: a user may change how they are addressed and how the
 * interface behaves, but not their own employee number or job title — those are HR records
 * someone else maintains, and self-service editing of them would undermine payroll.
 *
 * The e-mail address is also absent. Changing the login identifier is a security event that
 * needs re-verification, so it goes through its own endpoint.
 */
final class UpdateProfileRequest extends FormRequest
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
            'first_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'locale' => ['nullable', 'string', Rule::in(config('asids.regional.supported_locales', ['en']))],
            'theme' => ['nullable', 'string', Rule::in(['system', 'light', 'dark'])],
        ];
    }
}
