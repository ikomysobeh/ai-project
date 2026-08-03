<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gateway auth (Flow C step 1, AI-BUILD-BRIEF.md §5.1): external clients
 * authenticate with our own hashed Bearer token, not a Sanctum session —
 * this middleware is what "auth token → resolve app + tenant" means for
 * /v1/* routes, cached in Redis so a hot token doesn't hit Postgres on
 * every gateway call.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->bearerToken();

        if ($raw === null) {
            return response()->json(['error' => 'Missing bearer token.'], 401);
        }

        $hash = ApiToken::hash($raw);
        $cacheKey = ApiToken::cacheKeyFor($hash);
        $cached = Redis::get($cacheKey);

        if ($cached !== null) {
            $data = json_decode($cached, true);

            if ($data === null) {
                return response()->json(['error' => 'Invalid or revoked API token.'], 401);
            }
        } else {
            // Bypasses the tenant scope like the invite-accept lookup does —
            // the token hash itself is the auth boundary here, there's no
            // tenant context yet to scope by.
            $token = ApiToken::withoutGlobalScopes()->where('token_hash', $hash)->first();

            if (! $this->isUsable($token)) {
                // Cache the miss too (short TTL) so a bad/replayed token can't
                // be used to hammer the database.
                Redis::setex($cacheKey, 30, 'null');

                return response()->json(['error' => 'Invalid or revoked API token.'], 401);
            }

            $data = [
                'token_id' => $token->id,
                'app_id' => $token->app_id,
                'tenant_id' => $token->tenant_id,
                'rate_limit' => $token->rate_limit,
                'daily_quota' => $token->daily_quota,
            ];

            Redis::setex($cacheKey, 300, json_encode($data));
        }

        app(TenantContext::class)->set($data['tenant_id']);

        $request->attributes->set('gateway_app_id', $data['app_id']);
        $request->attributes->set('gateway_token_id', $data['token_id']);
        $request->attributes->set('gateway_rate_limit', $data['rate_limit']);
        $request->attributes->set('gateway_daily_quota', $data['daily_quota']);

        return $next($request);
    }

    private function isUsable(?ApiToken $token): bool
    {
        if ($token === null || $token->revoked_at !== null) {
            return false;
        }

        return $token->expires_at === null || $token->expires_at->isFuture();
    }
}
