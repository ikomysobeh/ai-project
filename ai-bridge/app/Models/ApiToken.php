<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['app_id', 'name', 'prefix', 'token_hash', 'rate_limit', 'daily_quota', 'expires_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    /**
     * Returns the raw token (shown to the caller exactly once) alongside
     * its hash and display prefix (what's persisted — see mvp-scope.md §6).
     *
     * @return array{raw: string, hash: string, prefix: string}
     */
    public static function generate(): array
    {
        $raw = 'tf_'.Str::random(40);

        return [
            'raw' => $raw,
            'hash' => static::hash($raw),
            'prefix' => substr($raw, 0, 10),
        ];
    }

    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function cacheKeyFor(string $tokenHash): string
    {
        return "api_token:{$tokenHash}";
    }
}
