<?php

namespace App\Http\Requests\Api;

use App\Services\Gateway\GatewayModelCatalog;
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
            // The console dropdown already only lists gateway-usable models
            // (GatewayModelCatalog), but this endpoint can be called
            // directly — reject playwright/*|atlas/* here too, rather than
            // letting the app get created and only fail on its first real
            // gateway call.
            'default_model' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, $value, \Closure $fail): void {
                    if (! GatewayModelCatalog::isUsable($value)) {
                        $fail('This model is not usable through the gateway — pick a plain gemini-* model.');
                    }
                },
            ],
            'knowledge_base_id' => [
                'nullable',
                Rule::exists('knowledge_bases', 'id')->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }
}
