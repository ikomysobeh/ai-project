<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges auth to the tenant-scoped models: once Sanctum resolves the user,
 * this sets the tenant every BelongsToTenant query/create will use for the
 * rest of the request. No-op for unauthenticated requests (e.g. signup,
 * invite accept) — those either don't touch tenant-scoped models or look
 * them up by an unguessable token instead (see InviteController::accept).
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            app(TenantContext::class)->set($user->tenant_id);
        }

        return $next($request);
    }
}
