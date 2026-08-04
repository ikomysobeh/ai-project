# 14 — Laravel Octane: Should TokenForge Use It?

## TL;DR

**Not yet.** Octane is a real option later, but right now it buys little
performance (this app is I/O-bound on Gemini/network calls, not framework
bootstrap) and it introduces one concrete bug risk in this codebase today:
the `TenantContext` singleton would leak tenant data between requests inside
the same worker. If you do adopt Octane later, that one fix
(`singleton()` → `scoped()`) is mandatory, not optional.

---

## 1. What Octane actually is

Normal PHP-FPM model (what TokenForge runs today):

- Every HTTP request = a **brand new PHP process/worker** picks it up.
- Laravel boots completely from scratch: autoloader, service container,
  config files, service providers, routes — all rebuilt every single request.
- When the request ends, **everything is destroyed**. Zero shared state
  between requests. This is why PHP has traditionally been "safe by default"
  — you can't accidentally leak request A's data into request B.

Octane model:

- Octane boots Laravel **once** per worker process, then keeps that booted
  application **in memory** and reuses it for thousands of subsequent
  requests, only swapping in the new `Request`/`Response` objects each time.
- It needs a special application server to do this — either:
  - **Swoole** (PHP extension, event-loop based), or
  - **RoadRunner** (separate Go binary, communicates with PHP over a
    lightweight protocol).
- Because the container/services/singletons stay alive across requests,
  you skip the entire "rebuild everything" cost → faster response times
  and lower CPU per request, at high request volume.

This is the entire value proposition: **eliminate bootstrap overhead**.
It does not make your database queries faster, it does not make an outbound
HTTP call to Gemini faster, and it does not reduce network latency.

---

## 2. Does TokenForge's workload actually benefit?

Look at where time is actually spent per request type in this app:

| Endpoint | Where the time goes | Bootstrap % of total time |
|---|---|---|
| `POST /v1/chat/completions` (gateway) | Waiting on WebAI-to-API → Gemini over the network (hundreds of ms to several seconds) | Tiny |
| Dashboard/console pages (Inertia) | DB queries (Postgres), rendering | Small-ish |
| RAG ingestion (`IngestDocumentJob`) | Ollama embedding calls, chunking, DB writes — runs in the queue worker, which is **already long-running** | Not applicable — Octane doesn't touch queue workers |
| Auth (login/signup) | DB + password hashing (`bcrypt`/`argon2`, deliberately slow) | Tiny |

The one endpoint that matters most for user-perceived latency — the
gateway — is **dominated by the Gemini round-trip**, which Octane cannot
speed up at all. Framework bootstrap in Laravel is typically 5-20ms;
a Gemini completion call is typically 500ms-several seconds. Removing the
bootstrap cost here is optimizing the part that was never the bottleneck.

Where Octane genuinely shines is high-throughput, CPU/bootstrap-bound APIs
serving thousands of requests/sec with very short handler logic (e.g. a
pure JSON CRUD API with no slow upstream calls). That is not this app's
current shape.

**Conclusion: at MVP/early-traffic stage, Octane's win here is marginal.**
It becomes worth revisiting if/when you have real production metrics
showing framework overhead (not Gemini latency, not DB) is a measurable
share of response time under real concurrent load.

---

## 3. The bug Octane would introduce today: `TenantContext`

This is the important part — read this section even if you decide not to
adopt Octane yet, because it's a useful thing to know about the codebase.

`app/Providers/AppServiceProvider.php`:
```php
$this->app->singleton(TenantContext::class);
```

`app/Http/Middleware/SetTenantContext.php`:
```php
public function handle(Request $request, Closure $next): Response
{
    if ($user = $request->user()) {
        app(TenantContext::class)->set($user->tenant_id);
    }

    return $next($request);
}
```

Notice: this **sets** the tenant when a user is authenticated, but it never
**clears** it when a request is unauthenticated (signup, invite-accept,
health checks, etc.).

- **Under PHP-FPM (today): this is harmless.** Every request gets a fresh
  container, so `TenantContext` always starts at `tenantId = null` anyway.
  The "never clears" gap is dead code, not a bug.

- **Under Octane: this becomes a real cross-tenant data leak.** A
  `singleton()` binding is created once per **worker process** and reused
  for every request that worker handles for its entire lifetime (thousands
  of requests), not once per request. Sequence that would actually happen:

  1. Worker #3 handles a request from User A (tenant 7). Middleware sets
     `TenantContext` → 7.
  2. Worker #3 immediately handles the next request in its queue — say, an
     unauthenticated `POST /signup`. `$request->user()` is null, so nothing
     resets the tenant.
  3. Any tenant-scoped model touched during that signup request (or any
     other unauthenticated/queued flow) is now silently scoped to **tenant 7**
     instead of failing closed (`1 = 0`) as `TenantScope` intends. Worse: if
     the next authenticated request in that same worker belongs to a
     *different* tenant but for some reason skips `SetTenantContext` (e.g. a
     route not in the `web`/`api` group), it would inherit the previous
     request's tenant.

  This is exactly the class of bug Octane's own docs warn about: **anything
  bound as `singleton()` is a worker-lifetime global, not a request-lifetime
  global**, once you're under Octane.

### The fix (required before ever enabling Octane)

Laravel has a container method built specifically for this: `scoped()`
instead of `singleton()`. A `scoped()` binding behaves exactly like a
singleton under normal PHP-FPM (one instance for the whole request), but
Octane (and queue workers) **automatically flush and rebuild it at the start
of every request**, so it can never leak between requests.

```php
// AppServiceProvider::register()
$this->app->scoped(TenantContext::class); // was: singleton()
```

That's the entire fix. No other code changes needed — `scoped()` is a drop-in
replacement for `singleton()` and is safe to make regardless of whether you
adopt Octane now or later (it costs nothing under plain PHP-FPM).

**Recommendation: make this change now, independent of the Octane decision.**
It's a one-line hardening fix with zero downside, and it removes a latent
tenant-isolation footgun before anyone forgets why it matters.

---

## 4. Other Octane compatibility checks for this codebase

Went through the rest of the app looking for the other common Octane
gotchas (static state, singletons, config mutated at runtime, long-lived
clients holding request-specific data):

| Concern | Status in TokenForge | Notes |
|---|---|---|
| Other `singleton()` bindings | ✅ Only `TenantContext` | Confirmed via grep across `app/Providers`, `app/Support`, `app/Services` |
| Static properties holding request state | ✅ None found | |
| `WebAiClient` (holds a `PendingRequest` built in `__construct`) | ✅ Safe | Not bound as a singleton — Laravel constructs a fresh instance per resolution. Even if it were reused, it holds no per-request data (base URL/timeout only, cookie override is passed as a method argument each call, not stored on the instance) |
| `ChatCompletionGateway` | ✅ Safe | Stateless service, no properties set outside constructor injection |
| Config mutated at runtime (`config(['x' => ...])` calls) | ✅ None found | Config is read-only at runtime everywhere in this app |
| Queue workers (`IngestDocumentJob`) | ⚠️ Already known issue, unrelated to Octane | Already documented: long-running `queue:work` processes cache config at boot; already requires `docker compose restart queue` after config changes. Octane doesn't make this better or worse — it's the same class of problem, already understood and already worked around |
| File uploads (`DocumentController::store`) | ✅ Safe | Octane handles `UploadedFile` per-request correctly; nothing here persists the file handle across requests |
| Sanctum session auth | ✅ Generally Octane-compatible | No known incompatibility; Sanctum reads cookies/session per request as normal |
| Inertia.js SSR | Not used | This app doesn't use Inertia SSR, so no interaction with Octane's SSR server feature either way |

---

## 5. What adopting Octane would actually require (infrastructure)

If you do move to Octane later, here's the real scope of work — this is
not a config flag, it's an architecture change to how requests reach PHP:

1. **Install the extension.** `composer require laravel/octane`, then choose
   a driver:
   - **Swoole** (recommended for this stack): needs `pecl install swoole`
     added to the Dockerfile's PHP build stage — same category of change
     already done for the `redis` extension (needs the same
     `apk add --virtual .build-deps $PHPIZE_DEPS` pattern already in place).
   - **RoadRunner**: needs a separate binary downloaded into the image
     instead of a PHP extension. Slightly simpler on Alpine, no PECL
     compilation step.

2. **Replace php-fpm as the thing nginx talks to.** Today: `nginx →
   php-fpm (fastcgi_pass)`. With Octane: Octane runs its own HTTP server
   directly (Swoole's built-in HTTP server), and `nginx` becomes a plain
   **reverse proxy** to that server's port instead of using fastcgi. This
   means editing `docker/nginx/default.conf` and likely restructuring the
   `app` service in `docker-compose.yml` to run `php artisan octane:start`
   instead of `php-fpm`.

3. **Worker recycling policy.** Long-running workers can accumulate memory
   (real leaks, not just Octane-specific ones — any accidental unbounded
   array growth in a service now lives for the worker's whole life instead
   of being freed every request). Octane supports `--max-requests` to
   auto-restart a worker after N requests as a safety net; this needs to be
   tuned and monitored.

4. **Local dev workflow changes.** `php artisan serve` still works
   separately, but the Docker `vite`/`app` dev loop would need Octane's
   watcher (`octane:start --watch`) wired up, or file changes won't be
   picked up without a manual restart — a real regression vs. today's
   php-fpm setup where every request already re-reads changed files for
   free.

None of this is hard, but it's a half-day-plus of infra work plus a testing
pass to confirm nothing else leaks state — not a "just add the package"
change.

---

## 6. Recommendation

- **Do now, regardless of Octane:** change `TenantContext` from
  `singleton()` to `scoped()` in `AppServiceProvider`. Zero cost, closes a
  latent tenant-isolation risk, and is a prerequisite if Octane is ever
  adopted.
- **Don't adopt Octane yet.** The app's slowest paths (Gemini calls, RAG
  embedding calls) are network-bound, not bootstrap-bound, so Octane's
  actual speedup here would be small relative to the infra complexity and
  new failure modes (worker memory leaks, the singleton class of bug, dev
  workflow changes) it introduces.
- **Revisit when:** you have real production traffic and can show (via
  actual response-time breakdowns, not guessing) that Laravel bootstrap
  time is a meaningful fraction of total request time — most likely to
  become true for the plain dashboard/API endpoints if the console UI
  gets heavy traffic, less likely for the gateway endpoint itself since
  that will always be dominated by the upstream Gemini call.
