<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TwoFactorChallengeRequest extends FormRequest
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
            'challenge' => ['required', 'string', 'size:64'],
            // Length is not constrained to six: a recovery code is also valid here, and it is
            // longer. Discriminating between the two is TwoFactorService's job.
            'code' => ['required', 'string', 'min:6', 'max:64'],
            'trust_device' => ['nullable', 'boolean'],
        ];
    }
}
