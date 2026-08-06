<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Presentation\Http\Requests;

use Asids\Core\Identity\Application\Services\AccountLinkService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Consumption of a signed invitation or reset link.
 *
 * Authorisation is open because the signature *is* the authorisation — verified by the
 * `signed` middleware on the route before this request is ever constructed.
 */
final class ConsumeAccountLinkRequest extends FormRequest
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
        $rules = [
            'purpose' => ['required', 'string', Rule::in([
                AccountLinkService::PURPOSE_INVITATION,
                AccountLinkService::PURPOSE_PASSWORD_RESET,
            ])],
            'fp' => ['required', 'string', 'size:64'],
        ];

        // The inspect endpoint reads the link without setting anything, so a password is
        // required only when the link is actually being consumed.
        if ($this->isMethod('POST')) {
            $rules['password'] = ['required', 'string', 'confirmed', Password::defaults()];
        }

        return $rules;
    }
}
