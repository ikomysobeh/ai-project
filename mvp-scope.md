# MVP Scope — TokenForge (API Gateway SaaS + RAG)

> **Status:** Approved to build (manager signed off on the prototype). **Scope final — all decisions closed, ready to implement.**
> **This document is the contract.** Anything not listed under "In scope" is explicitly out. Scope changes go through a written change note, not a hallway conversation. This is what stops an MVP from becoming endless.

---

## 1. One-line definition

A multi-tenant SaaS where a user creates apps, generates API tokens, and calls an OpenAI-compatible endpoint. The gateway authenticates the token, optionally augments the prompt with the user's own documents (RAG), rotates through the user's pool of Gemini accounts (via WebAI-to-API) to survive expiry, forwards the request, logs usage, and returns the answer. Admins get full visibility.

---

## 2. Locked decisions (the four that shape everything)

| Decision | Choice | Consequence |
|---|---|---|
| RAG in MVP? | **In — core feature** | Ingestion + retrieval + embeddings must ship in v1 |
| Account pool ownership | **Per user** | `upstream_accounts.user_id` is required; each user manages their own Gemini logins; rotation picks only from that user's pool |
| Stack | **Laravel API + React/TS frontend** | Backend = REST API only; frontend = separate React SPA calling it |
| Adding a Gemini account | **Simplest to ship = paste cookies** | User pastes the Gemini session cookie(s); we encrypt & store. No guided-login automation in v1 |

> **On "per user" accounts:** this is a real design commitment. A user's tokens can only be served by *that user's* Gemini accounts. If a user has zero healthy accounts, their requests fail — there's no shared fallback pool. Make this visible in the UI ("you have no active accounts, add one").

---

## 3. What the MVP IS and IS NOT

### ✅ In scope (v1 ships with all of this)
- Tenant + user accounts, roles (Owner / Admin / Member)
- Invite-link onboarding (signed, single-use, expiring)
- Apps: create, pick default model, attach one knowledge base
- API tokens: generate (shown once, hashed), list, revoke
- **Gateway endpoint:** OpenAI-compatible `POST /v1/chat/completions`
- **Per-user Gemini account pool** with rotation + health/expiry handling
- **RAG:** upload docs → chunk → embed → store; retrieve at query time
- Usage logging (requests, tokens, latency, status, used_rag)
- Admin dashboard: usage per user, problems, pool health
- Usage limits: rate limit + daily request quota per token

### ❌ Out of scope (explicitly NOT in v1)
- **Billing / payments / spend tracking** (manager confirmed out)
- Guided/automated Gemini login (v1 = paste cookies)
- Shared/platform-wide account pool (v1 = per-user only)
- Multiple upstream providers (v1 = Gemini via WebAI-to-API only)
- Streaming responses (SSE) — *nice-to-have, only if time allows*
- Team-level shared knowledge bases (v1 = KB owned by user/app)
- Fine-grained token scopes/permissions (v1 = one token = full app access)
- Multi-region, autoscaling, HA infra (v1 = single VPS + Docker)
- Mobile app, SDKs, webhooks, audit-log export
- Password reset flows beyond the basics, SSO, 2FA

> If anyone asks for something in the "Out" list, the answer is "v2." Write it down, don't build it.

---

## 4. Personas & roles

| Role | Can do |
|---|---|
| **Owner** | Everything in the tenant; created on signup; manages members |
| **Admin** | See all usage/problems/pool health; manage members; manage any app |
| **Member** | Create own apps, tokens, Gemini accounts, knowledge bases; see only own usage |

For the MVP, keep it to these three. No custom roles.

---

## 5. Core user flows (the ones we build and test)

### Flow A — Onboarding via invite
```
Owner/Admin creates invite → signed link (expires 7 days, single-use)
  → invitee opens /invite/{token}
  → fills name + password
  → account created inside the tenant with the assigned role
  → lands on empty dashboard with a "get started" checklist
```

### Flow B — Get to a working token (the golden path)
```
User adds a Gemini account (paste cookies) → account validated → status: active
  → creates an App (name + default model)
  → generates a Token (shown once, copied)
  → (optional) creates a Knowledge Base, uploads docs, attaches to app
  → calls POST /v1/chat/completions with the token → gets an answer
```
This is the flow the whole MVP exists to deliver. It must work end to end.

### Flow C — A request through the gateway (runtime)
```
external call: Bearer <token>
  1. authenticate token → resolve app + user + tenant (Redis cache)
  2. check rate limit + daily quota (reject early if exceeded)
  3. resolve model (request override or app default)
  4. if app has a KB + RAG enabled: embed query → vector search → inject context
  5. pick a healthy account from THIS USER's pool (least recently used)
  6. forward to WebAI-to-API /v1/chat/completions
  7. on 429/AuthError → mark account cooling/expired → try next → else graceful error
  8. log usage record
  9. return OpenAI-shaped response
```

### Flow D — Account expires (the failure we must handle)
```
gateway hits AuthError on account → marks it "expired"
  → tries next account in user's pool
  → if user has another active account: request succeeds, user never notices
  → if not: request fails with a clear error; dashboard shows "account expired, re-add cookies"
```

### Flow E — RAG ingestion
```
user uploads doc (txt / md — plain text only in v1)
  → queued background job: extract text → chunk (~800 tokens, overlap) → embed each chunk → store vector
  → KB status flips indexing → ready
  → chunks now retrievable at query time
```

---

## 6. Feature list (build checklist)

**Auth & tenancy**
- [ ] Signup creates tenant + owner
- [ ] Login (Laravel Sanctum SPA session auth)
- [ ] Invite creation + acceptance (signed, single-use, expiring)
- [ ] Role enforcement (Owner/Admin/Member) via middleware
- [ ] Tenant isolation via global query scope on every model

**Apps & tokens**
- [ ] Create/list/delete app (name, default_model, optional KB)
- [ ] Generate token (return raw once, store hash + prefix)
- [ ] List tokens, revoke token (instant, cache-invalidated)
- [ ] Per-token rate limit + daily quota

**Gemini account pool (per user)**
- [ ] Add account (paste cookies, encrypt at rest)
- [ ] Validate account on add (test call)
- [ ] List accounts with status (active/cooling/expired)
- [ ] Rotation picker (this user's active accounts, LRU)
- [ ] Mark cooling/expired on failure + retry next
- [ ] Health-check background job
- [ ] Re-add/replace cookies for an expired account

**Gateway**
- [ ] `POST /v1/chat/completions` (OpenAI-compatible)
- [ ] `GET /v1/models` (list allowed models)
- [ ] Token auth middleware + limit enforcement
- [ ] Forward to WebAI-to-API, map response back
- [ ] Error handling + graceful failures

**RAG**
- [ ] Create/list knowledge base (locked embedding model)
- [ ] Upload document(s) → background ingestion job
- [ ] Extract → chunk → embed → store in pgvector
- [ ] Document status tracking (indexing/ready/failed)
- [ ] Retrieval: embed query → top-K vector search (tenant + KB filtered)
- [ ] Prompt augmentation before forwarding
- [ ] Attach/detach KB to an app

**Dashboard (React)**
- [ ] Overview: usage stats, problems, pool health
- [ ] Apps / Tokens / Accounts / Knowledge / Team screens (from prototype)
- [ ] Admin: usage per member
- [ ] Playground: test a token with/without RAG

**Ops**
- [ ] Deploy on VPS (Docker: app, Postgres+pgvector, Redis, WebAI-to-API, Ollama)
- [ ] Encrypt secrets (cookies) at rest
- [ ] Basic logging (no secrets in logs)

---

## 7. API surface (v1)

**Gateway (token auth)**
```
POST /v1/chat/completions      OpenAI-compatible
GET  /v1/models
```

**Management (Sanctum session auth)**
```
POST   /auth/signup            create tenant + owner
POST   /auth/login
POST   /invites                create signed invite
POST   /invites/{token}/accept onboard
GET    /me                     current user + tenant

GET/POST/DELETE /apps
POST   /apps/{id}/tokens       generate (raw once)
GET    /apps/{id}/tokens
DELETE /tokens/{id}            revoke

GET/POST /accounts             per-user Gemini pool
POST   /accounts/{id}/reauth   replace cookies
POST   /accounts/{id}/test     health check

POST   /knowledge-bases
POST   /knowledge-bases/{id}/documents   upload → ingest
GET    /knowledge-bases/{id}/documents
DELETE /documents/{id}
POST   /apps/{id}/attach-kb

GET    /admin/usage
GET    /admin/problems
GET    /admin/health
```

---

## 8. Resolved decisions (closed before coding ✓)

All settled — no open questions block implementation:

1. **RAG document types (v1):** **txt + md only.** PDF/docx/URL deferred to v2. (Fewest extractors = fastest ship; just plain-text extraction.)
2. **Embedding model:** **Ollama `nomic-embed-text`** — free, private, already running. Locked per knowledge base.
3. **Embeddings compute:** **CPU** for the MVP. Add a GPU (RunPod serverless) later only if ingestion proves too slow. Does not block v1.
4. **SPA auth:** **Laravel Sanctum** (SPA session auth).
5. **Gemini accounts per user (cap):** **5** for the MVP.

Still needs a one-line sign-off (not a blocker for starting, but close it before launch):
- **WebAI-to-API license (AGPLv3)** — get the manager's written OK that it's acceptable for this deployment.

One tiny build note carried forward:
- **Cookie capture** needs a 3-line help text telling the user which cookie to copy and where from. Write it when building the "Add account" screen.

---

## 9. Definition of Done (how we know the MVP is finished)

The MVP is done when a brand-new user can, unassisted:
1. Accept an invite and log in.
2. Paste a Gemini cookie and see the account go **active**.
3. Create an app, pick a model, generate a token.
4. Create a knowledge base, upload a **.md or .txt** doc, watch it reach **ready**.
5. Attach the KB, then call `/v1/chat/completions` with the token and get a **RAG-grounded** answer.
6. Revoke the token and confirm the next call is rejected.
7. See their request appear in the admin usage view.

If all seven work on the deployed VPS, v1 ships. Nothing on the "Out of scope" list blocks this.

---

## 10. Suggested build order (map to tasks)

1. **Skeleton** — Laravel API + Postgres/pgvector + Redis + Sanctum auth + tenancy scaffolding.
2. **Auth & invites** — signup, login, invite flow, roles.
3. **Apps & tokens** — CRUD + token generation/hash/revoke.
4. **Gateway thin slice** — token → forward to WebAI-to-API → response. **One account, no rotation, no RAG.** Prove it works.
5. **Account pool + rotation** — per-user accounts, cookie paste, health checks, rotate-on-failure.
6. **Usage logging + limits** — records + rate/quota enforcement.
7. **RAG** — ingestion pipeline → pgvector → retrieval → wire into gateway.
8. **React dashboard** — build the real screens from the prototype against the API.
9. **Admin views** — usage/problems/health.
10. **Deploy + harden** — Docker on VPS, encrypt secrets, test the 7-point Definition of Done.

> Build step 4 (the thin gateway slice) first among the "real" work — it removes the biggest technical risk early. Everything else is CRUD and UI around a proven core.

---

## 11. Risks to watch (carry these into the build)

1. **Per-user pool starvation** — a user with all accounts expired has *no* fallback. Make the UI shout about it.
2. **Tenant/user data leak** — every query (including vector search) must filter correctly. Automate the scope; test it explicitly.
3. **Cookie fragility** — Gemini cookies expire often; rotation + health checks are mandatory, not optional.
4. **Embedding model mismatch** — ingest and query must use the same model; lock it on the KB.
5. **Secret leakage** — encrypt cookies at rest; never log them or full tokens.
6. **Scope creep** — the "Out of scope" list is the defense. Every "can we just add…" is a v2 note.
7. **AGPLv3** — unresolved license question is a real risk; close it before launch.
