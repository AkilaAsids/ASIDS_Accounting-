<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Asids\Core\Identity\Domain\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrative edit of another user's profile.
 *
 * `status`, `role_ids` and `password` are all absent: each has its own endpoint, because each
 * is a distinct privileged action that must be authorised, audited and rate-limited on its own
 * terms. Folding them into a general update is how "edit user" quietly becomes "escalate
 * privilege".
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'string', 'email:rfc,dns', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'employee_number' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'locale' => ['nullable', 'string', Rule::in(config('asids.regional.supported_locales', ['en']))],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }
}
