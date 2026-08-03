<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'app_id', 'token_id', 'upstream_account_id',
    'model', 'prompt_tokens', 'completion_tokens', 'total_tokens',
    'latency_ms', 'status', 'error_type', 'used_rag',
])]
class UsageRecord extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'used_rag' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    /** @return BelongsTo<ApiToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'token_id');
    }

    /** @return BelongsTo<UpstreamAccount, $this> */
    public function upstreamAccount(): BelongsTo
    {
        return $this->belongsTo(UpstreamAccount::class);
    }
}
