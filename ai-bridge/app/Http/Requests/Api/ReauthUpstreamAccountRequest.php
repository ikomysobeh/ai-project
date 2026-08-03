<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReauthUpstreamAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'secure_1psid' => ['required', 'string'],
            'secure_1psidts' => ['required', 'string'],
        ];
    }
}
