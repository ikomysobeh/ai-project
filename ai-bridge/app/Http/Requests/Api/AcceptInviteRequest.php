<?php

namespace App\Http\Requests\Api;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class AcceptInviteRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Required only when the invite itself has no email pinned to
            // it — enforced in the controller, where the invite is loaded.
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }
}
