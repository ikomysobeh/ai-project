# Architecture

## What this is

**TokenForge** is a multi-tenant SaaS: a user creates "apps," generates API tokens, and calls an OpenAI-compatible `POST /v1/chat/completions` endpoint. Behind the scenes, the request is forwarded to **Google Gemini** through **WebAI-to-API**, an internal service that talks to Gemini using browser cookies instead of an official API key. The platform rotates through each user's own pool of Gemini accounts to survive cookie expiry, can enrich prompts with the user's own documents (RAG), logs every call, and gives admins a usage dashboard.

The original scope/planning documents (kept at the repo root) are still useful background:
- [`mvp-scope.md`](../mvp-scope.md) — the authoritative feature scope.
- [`AI-BUILD-BRIEF.md`](../AI-BUILD-BRIEF.md) — the original architecture brief.

**This doc set describes what's actually built**, which has drifted from those plans in a few places — see [Divergences from the original plan](#divergences-from-the-original-plan) below.

## The three moving parts

```
┌──────────────────────────────┐        ┌──────────────────┐
│   ai-bridge (Laravel)        │  HTTP  │   WebAI-to-API    │
│   - Inertia.js + React pages │───────▶│   (FastAPI)       │───▶ Gemini
│   - JSON API (/api/*)        │◀───────│   cookies, no key │      (google.com)
│   - Gateway (/v1/*)          │        └──────────────────┘
│   - Auth, tenancy, tokens    │
│   - Gemini account rotation  │        ┌──────────────────┐
│   - RAG (embed + retrieve)   │───────▶│  Ollama (embed)   │
│   - Usage logging            │        └──────────────────┘
└──────────────────────────────┘
        │            │
  ┌─────▼───┐   ┌─────▼────┐
  │Postgres │   │  Redis   │
  │+pgvector│   │ (cache,  │
  │         │   │ sessions,│
  │         │   │ queue,   │
  │         │   │ limits)  │
  └─────────┘   └──────────┘

external API clients (Bearer <token>) ──▶ ai-bridge /v1/chat/completions
```

1. **WebAI-to-API** (`WebAI-to-API/`) — the engine that actually talks to Gemini, cloned from the open-source project [`HanaokaYuzu/Gemini-API`](https://github.com/HanaokaYuzu/Gemini-API)-based `gemini_webapi` library wrapped in a FastAPI service. TokenForge patches a few specific spots (marked `LOCAL PATCH (not upstream)` in comments) but otherwise treats it as a vendored dependency. See [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md).
2. **ai-bridge** (`ai-bridge/`) — a single Laravel application that is **both** the backend API and the server-rendered frontend, via **Inertia.js + React** (not a separate SPA — see divergence #2 below). All product logic lives here: multi-tenancy, auth, apps/tokens, the Gemini account pool, RAG, usage logging, and the gateway itself.
3. **Supporting services** — PostgreSQL (with the `pgvector` extension for embeddings), Redis (cache, sessions, queue, rate limiting), and Ollama (running `nomic-embed-text` for embeddings, CPU-only).

Everything runs via a single root-level `docker-compose.yml`. See [08-deployment-docker.md](08-deployment-docker.md).

## Key rule: Laravel is the only thing that talks to WebAI-to-API

- The React pages never call WebAI-to-API directly — they only call ai-bridge's own `/api/*` JSON endpoints.
- External API clients (a user's own code, curl, n8n, etc.) only call ai-bridge's `/v1/*` gateway endpoints with a Bearer token.
- WebAI-to-API is not published to the internet — in `docker-compose.yml` it's bound to `127.0.0.1:6969` (localhost-only, for local debugging) and reached internally at `http://webai:6969` by service name.
- The only class in ai-bridge that calls WebAI-to-API is [`app/Services/WebAiClient.php`](../ai-bridge/app/Services/WebAiClient.php).

## Repo layout

```
ai project/
├── ai-bridge/            Laravel + Inertia + React app (the whole product)
├── WebAI-to-API/         Cloned FastAPI service that talks to Gemini via cookies
├── docker-compose.yml    Brings up all 8 services with one command
├── .env.example          Root-level compose vars (Postgres creds)
├── docs/                 ← you are here
├── mvp-scope.md          Original scope document
└── AI-BUILD-BRIEF.md     Original architecture brief
```

Inside `ai-bridge/`, it's a standard Laravel 12 app: `app/`, `routes/`, `database/migrations/`, `resources/js/` (the React/Inertia frontend), `config/`, `docker/` (Dockerfile support files, nginx config, entrypoint script).

## Request flows

### Flow 1 — the gateway call (the core product)

```
external client, Bearer <token>
  → AuthenticateApiToken middleware: hash token, look up (Redis-cached) → resolve app/tenant, reject if revoked/expired
  → EnforceApiTokenLimits middleware: per-token rate limit + daily quota (Redis), reject BEFORE calling upstream
  → GatewayController::chatCompletions() → ChatCompletionGateway::run():
      1. resolve model (request override, else app default)
      2. if app has an attached, ready knowledge base: embed the question (Ollama) → vector search top-K chunks → prepend as a system message
      3. pick a healthy account from THIS USER's pool (status=active, least-recently-used first)
      4. forward to WebAI-to-API's /v1/temporary/chat/completions with that account's cookies
      5. on 401 → mark account expired (with the real failure reason) → try next account
         on 429 → mark account cooling_down (5 min) → try next account
         any other non-2xx → return it immediately, no further rotation (it would fail identically on every account)
      6. log a usage_record (tokens, latency, status, used_rag)
      7. return the OpenAI-shaped response
```

Full detail: [07-gateway-and-rag.md](07-gateway-and-rag.md).

### Flow 2 — RAG ingestion (background)

```
user uploads a .txt/.md/.pdf/.docx file → Document row created (status: indexing) → IngestDocumentJob queued
  → extract text → chunk (~800 tokens, ~100 overlap) → embed each chunk via Ollama → store in pgvector
  → Document flips to ready/failed → KnowledgeBase status recomputed from its documents
```

Full detail: [07-gateway-and-rag.md](07-gateway-and-rag.md).

### Flow 3 — adding/re-authenticating a Gemini account

```
user pastes __Secure-1PSID / __Secure-1PSIDTS → UpstreamAccountController validates them with a real
  live "ping" call through WebAI-to-API's ephemeral cookie-override path → status becomes active or expired
  (with the real upstream failure reason recorded, e.g. a rotated/stale cookie)
```

Full detail: [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md).

## Multi-tenancy

Every tenant-owned table carries a `tenant_id`, enforced by a global Eloquent scope (`TenantScope`) attached via the `BelongsToTenant` trait, backed by a request-scoped `TenantContext` singleton. Critically, **it fails closed**: if no tenant is set in context, the scope returns zero rows rather than every tenant's rows. Full detail in [03-backend-laravel.md](03-backend-laravel.md#tenancy).

## Divergences from the original plan

The original `mvp-scope.md`/`AI-BUILD-BRIEF.md` docs describe the intended design. The actual implementation differs in a few places worth knowing before you go looking for something that isn't there:

1. **No account health-check background job.** The plan calls for a periodic job that proactively checks account health. It doesn't exist — health checks only happen synchronously, on demand, when a user adds/re-authenticates/tests an account (`UpstreamAccountController`). There is no `Schedule::` call anywhere in the app.
2. **The frontend is Inertia.js + React, not a separate SPA.** The plan describes "Backend = REST API only; frontend = separate React SPA calling it," and a `docker-compose.yml` `react` service. In reality there's no standalone SPA container — `ai-bridge` renders React pages server-side via Inertia (`Console\*Controller::index()` methods call `Inertia::render(...)`), and the `vite` compose service is a dev/HMR server, not a production frontend host. The JSON API (`/api/*`) still exists and is what the React pages' `fetch()` calls hit for mutations, but page routing/loading is server-driven. See [04-frontend-react.md](04-frontend-react.md).
3. **No separate `/admin/usage`, `/admin/problems`, `/admin/health` JSON endpoints.** That data is bundled into one Inertia page (`GET console/admin` → `Console\AdminController::index()`), not exposed as standalone JSON routes.
4. **The gateway lives outside `/api`.** `POST /v1/chat/completions` and `GET /v1/models` are registered via a `then:` callback in `bootstrap/app.php` specifically so they sit at bare `/v1/*`, not `/api/v1/*` — so an external OpenAI-client SDK can point its base URL at `/v1` directly.
5. **Rate limit and daily quota are per-token, not global.** Each `api_tokens` row has its own `rate_limit` (default 60/min) and `daily_quota` (default 1000/day), both overridable when a token is created.
6. **`User` and `Tenant` don't use the `BelongsToTenant` global scope.** `users.tenant_id` exists but is filtered manually wherever needed — `User::all()` is *not* automatically tenant-scoped the way `Application::all()` is.
