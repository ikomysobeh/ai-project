# AI BUILD BRIEF — TokenForge

> **Read this first. This is your single source of truth.**
> You are building an MVP of a multi-tenant API-gateway SaaS. This document tells you the architecture, the pieces, how they connect, what to build, and in what order. If something isn't specified here or in `mvp-scope.md`, ask — do not invent scope.

---

## 0. TL;DR — what you're building

A SaaS where a user creates "apps," generates API tokens, and calls an **OpenAI-compatible endpoint**. Behind the scenes, the request is forwarded to **Google Gemini** through an open-source tool called **WebAI-to-API** (which talks to Gemini using browser cookies, no API key). The platform rotates through the user's pool of Gemini accounts to survive cookie expiry, can enrich prompts with the user's own documents (**RAG**), logs everything, and shows admins a dashboard.

**Three moving parts:**
1. **WebAI-to-API** — the engine that actually talks to Gemini. *We clone it, we don't rewrite it.*
2. **Laravel** — the **bridge / gateway**. All our logic lives here (auth, tenancy, tokens, rotation, RAG, logging). It sits in front of WebAI-to-API.
3. **React (TypeScript)** — the **frontend** dashboard users and admins click around in.

Everything runs together via **Docker Compose** so the whole stack comes up with one command.

---

## 1. The mental model (read this until it's obvious)

```
┌──────────────┐        ┌─────────────────────────┐        ┌──────────────────┐
│  React SPA   │  HTTP  │      LARAVEL (bridge)    │  HTTP  │   WebAI-to-API   │
│  (dashboard) │───────▶│  - auth / tenancy        │───────▶│  (FastAPI)       │───▶ Gemini
│              │◀───────│  - tokens / apps         │◀───────│  cookies, no key │
└──────────────┘        │  - account rotation      │        └──────────────────┘
                        │  - RAG (embed+retrieve)  │
   external API         │  - usage logging         │        ┌──────────────────┐
   clients ────────────▶│  /v1/chat/completions    │───────▶│  Ollama (embed)  │
   Bearer <token>       └─────────────────────────┘        └──────────────────┘
                              │            │
                        ┌─────▼───┐   ┌────▼─────┐
                        │Postgres │   │  Redis   │
                        │+pgvector│   │ (cache)  │
                        └─────────┘   └──────────┘
```

- **React never talks to WebAI-to-API directly.** It only talks to Laravel.
- **External API clients** (a user's own code, curl, n8n) also only talk to Laravel, using a token.
- **Laravel is the only thing that talks to WebAI-to-API.** WebAI-to-API is internal — not exposed to the internet.
- **Ollama** runs the embedding model for RAG (CPU mode).

> Key sentence to internalize: **Laravel is a bridge.** It authenticates the caller, decides which Gemini account to use, optionally adds document context, forwards to WebAI-to-API, and logs the result. It does not run any AI model itself except calling out to Ollama for embeddings.

---

## 2. The pieces in detail

### 2.1 WebAI-to-API (the engine — we clone, don't build)
- Open-source FastAPI server: `https://github.com/Amm1rr/WebAI-to-API`
- Talks to Gemini using **browser cookies** (no API key).
- Exposes an **OpenAI-compatible** endpoint: `POST /v1/chat/completions`, plus `GET /v1/models`.
- Runs on a port (commonly `6969`/`8000`). We run it **inside Docker**, on the internal network only.
- **Its auth expires** — cookies die. It returns an `AuthError` when that happens. **This is the entire reason our rotation system exists.**
- **Your job with it:** clone it, get it running in a container, and call it from Laravel. Do **not** modify its internals unless absolutely necessary.

> ⚠️ License: recent versions are **AGPLv3**. Note it; a human is confirming it's OK. Don't block on it.

### 2.2 Laravel (the bridge — this is where you build the most)
Holds ALL our business logic:
- Multi-tenancy (every row scoped to a tenant)
- Auth (Laravel **Sanctum**, SPA session) + invite onboarding
- Apps, API tokens (hashed, revocable)
- **Per-user Gemini account pool** + rotation + health checks
- **RAG:** ingest documents → embed via Ollama → store in pgvector → retrieve at query time
- The public **gateway endpoint** `POST /v1/chat/completions` that external clients hit
- Usage logging + rate/quota limits
- Admin dashboard APIs

### 2.3 React + TypeScript (the frontend)
- A separate SPA (the prototype `frontend-prototype.html` shows the exact screens to build).
- Talks only to Laravel's management API (Sanctum session auth).
- Screens: Dashboard, Apps, Tokens, Gemini Accounts, Knowledge (RAG), Playground, Admin, Team/Invites.

### 2.4 Supporting services
- **PostgreSQL + pgvector** — relational data AND vector embeddings in one DB.
- **Redis** — token→app cache, rate-limit counters, account rotation state.
- **Ollama** — runs `nomic-embed-text` for embeddings (CPU).

---

## 3. Locked decisions (do not deviate)

| Thing | Decision |
|---|---|
| RAG in MVP | **Yes**, core feature |
| Account pool ownership | **Per user** (each user's own Gemini accounts; no shared pool) |
| Backend | **Laravel** (REST API only) |
| Frontend | **React + TypeScript** (separate SPA) |
| Auth | **Laravel Sanctum** (SPA session) |
| Add Gemini account | **Paste cookies** (encrypt at rest); no login automation |
| RAG doc types | **txt + md only** (plain text; no PDF parser in v1) |
| Embedding model | **Ollama `nomic-embed-text`**, locked per knowledge base |
| Embeddings compute | **CPU** |
| Gemini accounts per user | **max 5** |
| Billing | **OUT of scope** — track usage counts only, no money |
| Orchestration | **Docker Compose** — one command brings the whole stack up |

Full detail lives in `mvp-scope.md`. This brief and that file must agree; if they ever conflict, `mvp-scope.md` wins.

---

## 4. Docker — the whole stack in one command

Target: a developer clones the repo, runs one command, and everything is up. Use **Docker Compose** with these services:

```
services:
  laravel        # the bridge / API            (php-fpm + nginx, or Laravel Sail)
  react          # the frontend SPA            (vite dev server, or built + served by nginx)
  webai          # cloned WebAI-to-API         (its own Dockerfile from the repo)
  postgres       # Postgres 16 + pgvector      (use pgvector/pgvector image)
  redis          # cache + rate limits
  ollama         # embeddings (nomic-embed-text)
```

Requirements:
- A single `docker-compose.yml` at the repo root that starts all of the above.
- Internal Docker network: Laravel reaches `webai`, `postgres`, `redis`, `ollama` **by service name** (e.g. `http://webai:8000`). WebAI is **not** published to the host except for local debugging.
- A `.env.example` with every variable needed (DB creds, Redis, WebAI base URL, Ollama URL, app key).
- On first boot: run migrations, and pull the Ollama model (`ollama pull nomic-embed-text`).
- Document the exact commands in a `README.md` (see §9).

> The goal is **"clone → `docker compose up` → it works."** If a new developer needs more than the README to get running, the Docker setup isn't done.

---

## 5. The two request flows you MUST get right

### 5.1 The gateway call (runtime — external client → answer)
This is the core of the product. When a request hits `POST /v1/chat/completions` on Laravel:

```
1. AUTH        read Bearer token → hash → look up in Redis (fallback DB)
               → resolve app + user + tenant. Reject if revoked/expired.
2. LIMITS      check rate limit + daily quota (Redis). Reject BEFORE upstream if exceeded.
3. MODEL       use request's model, else app default. Validate against /v1/models.
4. RAG         if app has a KB + RAG on:
                 embed the user's question (Ollama)
                 → vector search top-K chunks (filtered by tenant_id + kb_id)
                 → inject chunks into the prompt as context
5. PICK ACCT   from THIS USER's pool: WHERE status=active ORDER BY last_used ASC (LRU)
6. FORWARD     POST to http://webai:8000/v1/chat/completions with that account's cookies
7. FAIL/RETRY  on 429 → mark account cooling_down → try next
               on AuthError → mark account expired → try next
               all exhausted → return a clear, graceful error
8. LOG         write usage_record (tokens, latency, status, used_rag)
9. RETURN      OpenAI-shaped response to the caller
```

### 5.2 RAG ingestion (background — upload → searchable)
```
user uploads .txt/.md → queued job:
  extract text → chunk (~800 tokens, small overlap)
  → embed each chunk (Ollama nomic-embed-text)
  → store chunk text + vector + metadata in pgvector (with tenant_id + kb_id)
  → flip document status indexing → ready
```
Ingestion is a **queued background job** (Laravel queue). Never embed in the web request.

> **Two non-negotiable RAG rules:**
> 1. Same embedding model at ingest and query (lock it on the knowledge base).
> 2. Every vector search filters by `tenant_id` — or one tenant retrieves another's docs. This is a security bug, not a feature gap. Test it.

---

## 6. Data model (build these tables)

Every table carries `tenant_id`. Enforce it with a **global query scope** on the base model so no query can forget it — this is the #1 defense against data leaks.

```
tenants(id, name, slug, status, created_at)
users(id, tenant_id, name, email, password, role[owner|admin|member], status)
invites(id, tenant_id, email?, role, signed_token, expires_at, used_at)
apps(id, tenant_id, user_id, name, default_model, knowledge_base_id?, status)
api_tokens(id, app_id, tenant_id, name, token_hash, prefix,
           rate_limit, daily_quota, last_used_at, revoked_at, expires_at)
upstream_accounts(id, tenant_id, user_id,           -- PER USER
           label, cookies_encrypted, status[active|cooling_down|expired],
           last_used_at, cooldown_until, error_count, health_checked_at)
usage_records(id, tenant_id, app_id, token_id, upstream_account_id,
           model, prompt_tokens, completion_tokens, total_tokens,
           latency_ms, status, error_type, used_rag, created_at)

-- RAG --
knowledge_bases(id, tenant_id, user_id, name, embedding_model, status)
documents(id, knowledge_base_id, tenant_id, source_name, source_type, status, created_at)
chunks(id, document_id, knowledge_base_id, tenant_id,
           content, token_count, embedding vector, metadata, created_at)
```
(No billing/wallet tables — out of scope.)

---

## 7. API surface to implement

**Gateway (token auth — what external clients call):**
```
POST /v1/chat/completions      OpenAI-compatible, optional RAG, per-user rotation
GET  /v1/models
```

**Management (Sanctum session — what React calls):**
```
POST /auth/signup              creates tenant + owner
POST /auth/login
POST /invites                  create signed invite link
POST /invites/{token}/accept
GET  /me

GET/POST/DELETE /apps
POST /apps/{id}/tokens         generate (return raw ONCE)
GET  /apps/{id}/tokens
DELETE /tokens/{id}            revoke (instant)

GET/POST /accounts             per-user Gemini pool (max 5)
POST /accounts/{id}/reauth     replace cookies
POST /accounts/{id}/test       health check

POST /knowledge-bases
POST /knowledge-bases/{id}/documents   upload → triggers ingestion job
GET  /knowledge-bases/{id}/documents
DELETE /documents/{id}
POST /apps/{id}/attach-kb

GET /admin/usage               requests, tokens, errors per member
GET /admin/problems            failed requests, expired accounts
GET /admin/health              account pool health
```

---

## 8. Build order (do it in this sequence)

Do NOT build everything at once. Each step should run before the next starts.

1. **Docker skeleton** — compose file with all 6 services; Laravel + Postgres+pgvector + Redis reachable; `docker compose up` works.
2. **DB + models** — all migrations from §6; base model with tenant global scope; seed a demo tenant.
3. **Auth & invites** — Sanctum signup (creates tenant+owner), login, invite create/accept, role middleware.
4. **Apps & tokens** — CRUD; token generate (raw once, store hash+prefix); revoke.
5. **Gateway thin slice** — `POST /v1/chat/completions` → auth token → forward to WebAI-to-API → return. **ONE hardcoded account, no rotation, no RAG.** Prove the bridge works end-to-end. ← *biggest risk, do it early*
6. **Account pool + rotation** — per-user accounts (paste cookies, encrypted), status machine, LRU picker, retry-on-failure, health-check job.
7. **Usage logging + limits** — usage_records on every call; rate limit + daily quota enforced before upstream.
8. **RAG** — knowledge bases, upload → ingestion job → Ollama embed → pgvector; retrieval → prompt augmentation wired into gateway step 4.
9. **React dashboard** — build the real screens from `frontend-prototype.html` against the API.
10. **Admin views + harden** — usage/problems/health; encrypt secrets; no secrets in logs; run the Definition of Done.

**Definition of Done** (from `mvp-scope.md`): a new user can, on the deployed stack — accept an invite, paste a cookie (account goes active), create an app + token, upload a .md/.txt to a KB (reaches ready), attach it, call the gateway and get a **RAG-grounded** answer, revoke the token (next call rejected), and see the request in admin usage. All 8 → ship.

---

## 9. What to deliver

- A single repo, `docker compose up` brings up the whole stack.
- `README.md` with: prerequisites, exact setup commands, how to get a WebAI-to-API cookie, how to run migrations, how to test the golden path.
- `.env.example` with every variable.
- Laravel API implementing §6/§7.
- React SPA implementing the prototype screens.
- WebAI-to-API cloned and containerized (unmodified where possible).
- Clear commit history following the build order in §8.

---

## 10. Rules & gotchas (don't get burned)

1. **Laravel is a bridge** — it never runs Gemini itself; it calls WebAI-to-API. Don't try to reimplement Gemini access.
2. **Never expose WebAI-to-API publicly** — internal Docker network only. All external traffic goes through Laravel.
3. **Tenant isolation is a security requirement** — global scope on every model; vector search filtered by tenant_id. Test cross-tenant access explicitly.
4. **Encrypt Gemini cookies at rest** (Laravel `Crypt`). Never log cookies or full tokens.
5. **Tokens:** show raw once, store only a hash + display prefix. Revocation must be instant (invalidate Redis cache).
6. **Enforce limits BEFORE calling upstream**, never after.
7. **Embedding model must match** between ingest and query — lock it on the KB.
8. **Per-user pool has no fallback** — if a user's 5 accounts all expire, their requests fail. Surface this clearly in the API/UI.
9. **Embed in background jobs**, never in the web request.
10. **Don't add scope.** Billing, PDF, streaming, shared pools, SSO = all v2. If unsure, ask; default to not building it.

---

## 11. Reference files in this project
- `mvp-scope.md` — the authoritative scope (wins over this brief on any conflict)
- `backend-architecture.md` — deeper backend detail (schema, services, folder layout)
- `api-token-saas-study.md` — original project study / rationale
- `frontend-prototype.html` — the exact UI screens to rebuild in React
