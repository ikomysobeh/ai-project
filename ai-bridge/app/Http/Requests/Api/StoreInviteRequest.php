<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInviteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(['owner', 'admin', 'member'])],
        ];
    }
}
