<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceApiTokenLimits;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/gateway.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusting '*' is safe here: in every deployment of this app
        // (including production — see docs/13-production-deployment.md),
        // the only thing ever forwarding requests to this container is a
        // reverse proxy on the same machine (loopback) — never an
        // untrusted network hop. Without this, Laravel can't tell a
        // request arrived over HTTPS once a proxy terminates TLS in front
        // of it, which breaks secure cookies and https:// URL generation.
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            // Must run before HandleInertiaRequests — its shared props
            // (tenant, nav counts) query tenant-scoped models.
            SetTenantContext::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Lets the (same-origin, cookie-based) SPA authenticate against the
        // API via session instead of a bearer token.
        $middleware->statefulApi();

        $middleware->appendToGroup('api', SetTenantContext::class);

        $middleware->alias([
            'role' => EnsureRole::class,
            'api.token' => AuthenticateApiToken::class,
            'api.limits' => EnforceApiTokenLimits::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
