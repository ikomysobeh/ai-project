<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachKnowledgeBaseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Nullable — sending null detaches the app's current KB.
            'knowledge_base_id' => [
                'nullable',
                Rule::exists('knowledge_bases', 'id')->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }
}
