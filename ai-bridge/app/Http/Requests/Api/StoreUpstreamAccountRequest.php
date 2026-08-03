<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreUpstreamAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'secure_1psid' => ['required', 'string'],
            'secure_1psidts' => ['required', 'string'],
        ];
    }
}
