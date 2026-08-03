# Backend (ai-bridge / Laravel)

`ai-bridge/` is a standard Laravel 12 app. It is **both** the JSON API and the server-rendered frontend (via Inertia — see [04-frontend-react.md](04-frontend-react.md)).

## Tenancy

Every tenant-owned table carries a `tenant_id`, enforced automatically so no query can forget it.

- **`App\Support\Tenancy\TenantContext`** ([app/Support/Tenancy/TenantContext.php](../ai-bridge/app/Support/Tenancy/TenantContext.php)) — a plain request-scoped singleton (registered in `AppServiceProvider`). API: `id(): ?int`, `set(?int)`, `has(): bool`. Not a facade, not global — just a service resolved via the container.
- **`App\Models\Concerns\BelongsToTenant`** ([app/Models/Concerns/BelongsToTenant.php](../ai-bridge/app/Models/Concerns/BelongsToTenant.php)) — trait used by every tenant-owned model. On boot: attaches `TenantScope` as a global scope, and on `creating`, auto-fills `tenant_id` from `TenantContext` if not already set.
- **`App\Models\Scopes\TenantScope`** ([app/Models/Scopes/TenantScope.php](../ai-bridge/app/Models/Scopes/TenantScope.php)) — the actual filter. **Fails closed**: if no tenant is in context, it adds `whereRaw('1 = 0')` (zero rows), never "all tenants' rows."
- **`App\Http\Middleware\SetTenantContext`** — if `$request->user()` resolves, sets `TenantContext` from `$user->tenant_id`. Wired into the `web` group (before `HandleInertiaRequests`, since its shared props query tenant-scoped models) and appended to the `api` group. The gateway routes (`/v1/*`) don't use this middleware — `AuthenticateApiToken` sets the tenant context itself from the token's `tenant_id`, since gateway auth is a Bearer token, not a session.

Models using `BelongsToTenant`: `Invite`, `Application`, `ApiToken`, `UpstreamAccount`, `KnowledgeBase`, `Document`, `Chunk`, `UsageRecord`.

**Not scoped**: `User` and `Tenant` themselves. `users.tenant_id` exists but is filtered manually (`User::where('tenant_id', ...)`) wherever needed — `User::all()` is *not* automatically tenant-safe.

**Deliberate scope bypasses** (all intentional, documented inline where they occur):
- `Api\InviteController::accept()` and `InviteAcceptController::show()` — nobody is authenticated yet; the unguessable `signed_token` itself is the auth boundary, so both use `Invite::withoutGlobalScopes()`.
- `AuthenticateApiToken` — looks up `ApiToken::withoutGlobalScopes()->where('token_hash', $hash)` for the same reason (token hash is the auth boundary for the gateway).
- `IngestDocumentJob` — queue jobs have no HTTP request/middleware pipeline, so it manually loads the `Document` via `withoutGlobalScopes()` and then calls `TenantContext::set()` before touching anything else.

**Route-model-binding caveat**: controllers type-hint `int $app` / `int $token` rather than `Application $app` on purpose. Implicit route-model binding runs (via `SubstituteBindings`) *before* `SetTenantContext` sets the tenant, so binding against the fail-closed scope would 404. Lookups happen manually inside the controller body, after all middleware has run.

## Auth

- **Session-based**, via Laravel **Sanctum** (`auth:sanctum` guard `web`) + `$middleware->statefulApi()` in `bootstrap/app.php` — this lets the same-origin app authenticate against `/api/*` via cookie/session rather than a bearer token. `personal_access_tokens` table exists but isn't actively used — this is not token-based API auth for the console, only for the separate `/v1/*` gateway (see below).
- **Signup** — two parallel entry points that both funnel into `App\Actions\CreateTenantAndOwner::handle()` (wraps in `DB::transaction()`, creates a `Tenant` with `Tenant::uniqueSlugFor()`, then a `User` with `role = 'owner'`):
  1. JSON: `Api\AuthController::signup()`.
  2. Fortify's own registration, via `App\Actions\Fortify\CreateNewUser`.
- **Login/logout/me** — `Api\AuthController::login()/logout()/me()`. Fortify also drives the classic web login/2FA/passkey/password-reset views (`FortifyServiceProvider`), rendered as Inertia pages.
- **Invites** (per mvp-scope.md Flow A):
  - Create: `Api\InviteController::store()`, `role:owner,admin` only. `signed_token = Str::random(48)`, expires in 7 days.
  - Accept: `Api\InviteController::accept()` (public) validates the token, requires an email (pinned on the invite or supplied), rejects if that email already has a `User`, creates the user with the invite's `tenant_id`/`role`, marks the invite used, logs them in.
  - `InviteAcceptController::show()` — public Inertia page at `GET /invite/{token}` rendering invite metadata for the accept form.
- **Roles**: `owner` / `admin` / `member`, a plain string column (not an enum). `User::isOwner()`, `User::isAdmin()` (owner or admin) helpers. Enforced by `App\Http\Middleware\EnsureRole` (alias `role`, e.g. `role:owner,admin`).

## Domain models

All under `ai-bridge/app/Models/`. Fillable/Hidden are declared via PHP 8 attributes (`#[Fillable(...)]`, `#[Hidden(...)]`), not the classic `protected $fillable` array.

| Model | Table | Notes |
|---|---|---|
| `Tenant` | `tenants` | `name, slug, status`. No `BelongsToTenant` (it *is* the tenant). |
| `User` | `users` | `tenant_id, name, email, password (hashed), role, status`. Uses `PasskeyAuthenticatable`, `TwoFactorAuthenticatable`. Not tenant-scoped (see above). |
| `Invite` | `invites` | `email, role, signed_token, expires_at, used_at`. |
| `Application` (table `apps`) | `apps` | `user_id, name, default_model, knowledge_base_id, status`. Named `Application` to avoid colliding with the root `App` namespace. Explicit FK `app_id` on its `tokens()`/`usageRecords()` relations (Eloquent would otherwise guess `application_id`). |
| `ApiToken` | `api_tokens` | `app_id, name, prefix, token_hash (hidden), rate_limit, daily_quota, expires_at`. Statics: `generate()` → `{raw: 'tf_'.Str::random(40), hash, prefix: substr($raw,0,10)}`; `hash($raw)` = sha256; `cacheKeyFor($hash)` = `"api_token:{hash}"`. |
| `UpstreamAccount` | `upstream_accounts` | `user_id, label, cookies_encrypted (encrypted:array, hidden), status, error_count, last_error`. `cookies_encrypted` holds `{psid, psidts}`, AES-encrypted via Laravel's `encrypted:array` cast (app-key based). Helpers: `markHealthy()`, `markExpired($reason)`, `markCoolingDown()`. See [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md). |
| `UsageRecord` | `usage_records` | `app_id, token_id, upstream_account_id, model, prompt/completion/total_tokens, latency_ms, status, error_type, used_rag`. No `updated_at` (`const UPDATED_AT = null`). |
| `KnowledgeBase` | `knowledge_bases` | `user_id, name, embedding_model, status`. |
| `Document` | `documents` | `knowledge_base_id, source_name, source_type, status`. |
| `Chunk` | `chunks` | `document_id, knowledge_base_id, content, token_count, metadata (array), embedding`. `embedding` is a custom `Attribute` bridging pgvector: getter parses Postgres's `"[0.1,0.2,...]"` string into a `float[]`; setter wraps a `float[]` into a raw `'[...]'::vector` expression, built only from `floatval()`-coerced numbers (never raw user input). |

## Controllers

Two parallel controller sets for most areas: **`Console\*`** controllers are read-only — each `index()` renders one Inertia page with everything that page needs as props. **`Api\*`** controllers handle all mutations, called by the React pages' own `fetch()` calls (see [console-api.ts](04-frontend-react.md#api-client)).

| Area | Console (read) | Api (mutate) |
|---|---|---|
| Auth | — | `Api\AuthController` (signup/login/logout/me), `Api\InviteController` (store/accept) |
| Dashboard | `Console\DashboardController` | — |
| Apps | `Console\AppsController` | `Api\AppController` (index/store/destroy/attachKnowledgeBase) |
| Tokens | `Console\TokensController` | `Api\ApiTokenController` (index/store/destroy) |
| Gemini accounts | `Console\AccountsController` | `Api\UpstreamAccountController` (index/store/reauth/test) |
| Knowledge/RAG | `Console\KnowledgeController` | `Api\KnowledgeBaseController`, `Api\DocumentController` |
| Gateway | `Console\PlaygroundController` (index + `send`, runs the same pipeline as the real gateway but session-authenticated) | `Api\GatewayController` (chatCompletions/models — the actual public `/v1/*` surface) |
| Admin | `Console\AdminController`, `Console\TeamController` (both `role:owner,admin`) | — |
| Settings | `Settings\ProfileController`, `Settings\SecurityController` | — |

Visibility rule repeated across several `Api\*` controllers: **members see only their own resources; owners/admins see everything in the tenant.**

## The gateway pipeline

See [07-gateway-and-rag.md](07-gateway-and-rag.md) for the full `ChatCompletionGateway::run()` walkthrough.

## RAG

See [07-gateway-and-rag.md](07-gateway-and-rag.md).

## WebAiClient — the only bridge to WebAI-to-API

[`app/Services/WebAiClient.php`](../ai-bridge/app/Services/WebAiClient.php) is the **only** class in ai-bridge that talks to WebAI-to-API. Two methods:
- `chatCompletions(array $payload, ?array $cookieOverride)` — posts to `/v1/temporary/chat/completions` (deliberately not `/v1/chat/completions` — see [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md) for why). If `$cookieOverride` is given, sends `X-Gemini-1PSID`/`X-Gemini-1PSIDTS` headers.
- `models()` — `GET /v1/models`.

## Rate limiting & quota

Both middleware only run on the gateway routes (`routes/gateway.php`), and both are **per-token**, not global — each `api_tokens` row has its own `rate_limit` (default 60/min) and `daily_quota` (default 1000/day), settable when the token is created.

1. **`AuthenticateApiToken`** (alias `api.token`) — hashes the bearer token (sha256), checks Redis first (`api_token:{hash}`, cached 300s on a hit, 30s `'null'` sentinel on a miss so a bad/replayed token can't hammer Postgres), else looks up `ApiToken::withoutGlobalScopes()`. Sets `TenantContext` plus four request attributes (`gateway_app_id`, `gateway_token_id`, `gateway_rate_limit`, `gateway_daily_quota`). Revocation is instant because `ApiTokenController::destroy()` explicitly `Redis::del()`s the cache key on revoke.
2. **`EnforceApiTokenLimits`** (alias `api.limits`, only on `POST /v1/chat/completions`) — two independent `RateLimiter` keys: `gateway-rate:{tokenId}` (60s window) and `gateway-quota:{tokenId}:{Y-m-d}` (86400s window). Both checked and `hit()` **before** `$next($request)` — i.e. before the request ever reaches the gateway service or upstream.

## Jobs

Only one queued job exists: **`App\Jobs\IngestDocumentJob`** (`ShouldQueue`, `tries = 3`) — see [07-gateway-and-rag.md](07-gateway-and-rag.md#rag-ingestion). Runs in the dedicated `queue` container (`php artisan queue:work --tries=3 --backoff=5`), separate from the web-facing `app`/`nginx` containers, so embedding never happens inside a web request.

There is **no scheduled/periodic job** anywhere in the app (no `Schedule::` calls) — notably, no background health-check sweep for the Gemini account pool. Health checks only happen synchronously, on demand, when a user adds/tests/re-authenticates an account.

## Config & environment variables

`config/services.php`:
```php
'webai'  => ['base_url' => env('WEBAI_BASE_URL', 'http://webai:6969')],
'ollama' => [
    'base_url'    => env('OLLAMA_BASE_URL', 'http://ollama:11434'),
    'embed_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
],
```

Key vars in `ai-bridge/.env.example` beyond Laravel's own defaults:

| Var | Purpose |
|---|---|
| `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_DATABASE/USERNAME/PASSWORD` | Must match the root `.env`'s `POSTGRES_*` values. |
| `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` | Redis backs sessions (needed for Sanctum), the RAG ingestion queue, and the API-token/rate-limit caches. |
| `WEBAI_BASE_URL` | Internal WebAI-to-API base URL. |
| `OLLAMA_BASE_URL`, `OLLAMA_EMBED_MODEL` | Ollama base URL and the **locked** embedding model name (stamped onto every new `KnowledgeBase`). |
| `FILESYSTEM_DISK=local` | Where RAG uploads land before ingestion (`rag-uploads/`). |

The root-level `ai project/.env.example` only holds the three `POSTGRES_*` vars consumed by `docker-compose.yml` — these must match `ai-bridge/.env`'s `DB_*` values.

## Routes

Full route tables: [09-api-reference.md](09-api-reference.md).

Route files and how they're wired (`bootstrap/app.php`):
- `routes/web.php` — Inertia pages, `web` middleware group (session, `SetTenantContext`, `HandleAppearance`, `HandleInertiaRequests`); also `require`s `routes/settings.php` and `routes/tokenforge-console.php`.
- `routes/api.php` — JSON API, `api` middleware group + `SetTenantContext` appended, `auth:sanctum` per-route.
- `routes/gateway.php` — registered via the `then:` callback in `bootstrap/app.php`, specifically so `/v1/chat/completions` and `/v1/models` sit at **bare `/v1/*`**, not `/api/v1/*` — so an external OpenAI-compatible client SDK can point its base URL directly at `/v1`.
- `routes/console.php` — only the stock `inspire` Artisan command; no HTTP routes, no scheduled tasks.

Middleware aliases (`bootstrap/app.php`): `role` → `EnsureRole`, `api.token` → `AuthenticateApiToken`, `api.limits` → `EnforceApiTokenLimits`.
