<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['app_id', 'name', 'prefix', 'token_hash', 'token_encrypted', 'rate_limit', 'daily_quota', 'expires_at'])]
#[Hidden(['token_hash', 'token_encrypted'])]
class ApiToken extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
            // Reversible (unlike token_hash) so the raw value can be shown
            // again on demand — see ApiTokenController::reveal(). Never
            // serialized by default (see #[Hidden] above); only ever read
            // explicitly via that one endpoint.
            'token_encrypted' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    /**
     * Returns the raw token alongside its hash and display prefix. The hash
     * is what auth actually checks against (see AuthenticateApiToken); the
     * raw value is also persisted encrypted (token_encrypted) purely so the
     * owner can view it again later — see ApiTokenController::reveal().
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
