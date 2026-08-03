<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'default_model' => ['required', 'string', 'max:255'],
            'knowledge_base_id' => [
                'nullable',
                Rule::exists('knowledge_bases', 'id')->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }
}
