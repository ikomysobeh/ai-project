<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flow C step 2 (AI-BUILD-BRIEF.md §5.1): rate limit + daily quota, checked
 * and rejected BEFORE calling upstream — never after. Must run after
 * AuthenticateApiToken (needs the attributes it sets).
 */
class EnforceApiTokenLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenId = $request->attributes->get('gateway_token_id');

        $rateKey = "gateway-rate:{$tokenId}";
        if (RateLimiter::tooManyAttempts($rateKey, $request->attributes->get('gateway_rate_limit'))) {
            return response()->json(['error' => ['message' => 'Rate limit exceeded.']], 429);
        }
        RateLimiter::hit($rateKey, 60);

        $quotaKey = "gateway-quota:{$tokenId}:".now()->format('Y-m-d');
        if (RateLimiter::tooManyAttempts($quotaKey, $request->attributes->get('gateway_daily_quota'))) {
            return response()->json(['error' => ['message' => 'Daily quota exceeded.']], 429);
        }
        RateLimiter::hit($quotaKey, 86400);

        return $next($request);
    }
}
