<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmTwoFactorRequest extends FormRequest
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
            'code' => ['required', 'string', 'min:6', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Authenticator apps display codes as "123 456" and users paste the space.
        $this->merge(['code' => preg_replace('/\s+/', '', (string) $this->input('code')) ?? '']);
    }
}
