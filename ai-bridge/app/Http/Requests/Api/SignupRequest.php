<?php

namespace App\Http\Requests\Api;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class SignupRequest extends FormRequest
{
    use PasswordValidationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tenant_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ];
    }
}
