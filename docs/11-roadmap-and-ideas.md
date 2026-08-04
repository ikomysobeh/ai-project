# Roadmap & Future Ideas

The MVP scope (`mvp-scope.md`) was deliberately locked down to ship a working v1 without scope creep. This doc is the opposite: a running backlog of what could come **after** v1 — nothing here is committed, prioritized officially, or promised to a user. Treat it as a menu, not a plan. When something here actually gets picked up, move it into a real proposal (see the `WebAI-to-API/openspec/` pattern for an example of how this project already tracks that) instead of leaving it as a bullet point.

Items are grouped by area, and roughly tagged by size so a planning conversation can pick a mix rather than one giant effort:
- 🟢 **Quick win** — days, one dev, low risk.
- 🟡 **Medium** — one to a few weeks, touches multiple layers.
- 🔴 **Big bet** — a real project of its own; needs a design doc before starting.

## Already known (v1 explicitly deferred these)

Straight from `mvp-scope.md` §3 — not new ideas, just the reminder that these were deferred on purpose, not forgotten:

- Billing / payments / spend tracking
- Guided/automated Gemini login (v1 = paste cookies only)
- Shared/platform-wide account pool (v1 = strictly per-user)
- Multiple upstream providers (v1 = Gemini only)
- Streaming responses (SSE)
- Team-level shared knowledge bases
- Fine-grained token scopes/permissions
- Multi-region / autoscaling / HA infra
- Mobile app, SDKs, webhooks, audit-log export
- SSO, full 2FA/passwordless beyond the basics already in Fortify

Several of the ideas below are just these, fleshed out.

## Gaps found while writing the developer docs

These aren't new features so much as loose ends from v1 itself, worth closing before piling on new scope:

- 🟢 **Account pool health-check job.** The plan called for one; it doesn't exist (see [03-backend-laravel.md](03-backend-laravel.md#jobs)). A scheduled command that periodically re-validates each `active` account (reusing the same live-ping logic `UpstreamAccountController` already has) would catch a rotated cookie *before* a real user request hits it and burns through the whole rotation pool. Also lets you flip an account to `expired` proactively instead of only reactively on a 401.
- 🟢 **Surface `last_error` more broadly.** It was just added to the account pool ([06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md)). Consider showing it on the admin "problems" view too, not just the owning user's own accounts page.
- 🟡 **Admin JSON API.** The plan describes `GET /admin/usage`, `/admin/problems`, `/admin/health` as standalone endpoints; today it's all bundled into one Inertia page's props. Fine for the dashboard, but blocks anything that wants that data programmatically (a CLI, a status page, an external monitor).
- 🟡 **Distributed auth-lock backend for WebAI-to-API.** `auth_lock_backend` only implements `in_memory` today — a real blocker the moment WebAI-to-API runs as more than one replica/worker (see [08-deployment-docker.md](08-deployment-docker.md)).

## Gemini account management

- 🟡 **Proactive expiry warning in the UI.** Once there's a health-check job (above), show a "your account will likely need re-auth soon" banner based on `error_count`/`health_checked_at` trends, instead of only reacting after it's already `expired`.
- 🔴 **Semi-automated re-login.** WebAI-to-API already has a full Playwright-driven interactive login flow (`AuthManager`, `verify_login.py`) for its own singleton account — it's just not wired into the per-user pool at all. A "re-authenticate via browser" option (launching a scoped, user-specific headful/remote-browser session) could replace manual cookie-pasting for users who find it painful, while keeping paste-cookies as the fallback.
- 🟡 **A tiny companion browser extension** that reads the two cookies for the currently active Gemini tab and one-click-copies them in the right format — removes the DevTools dance and reduces the "grabbed a stale `1PSIDTS`" failure mode described in [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md).
- 🟡 **Per-account usage/error trend view**, not just current status — a small time-series chart on the accounts page (requests served, error rate) would make it obvious when an account is degrading before it fully dies.

## Multi-provider support

- 🔴 **A real provider abstraction.** Right now "the gateway" and "Gemini" are conflated everywhere (`WebAiClient`, `ChatCompletionGateway`). Introducing an actual `Provider` interface (Gemini today, OpenAI/Anthropic/others tomorrow) would let an app pick its upstream, and would let the account pool concept generalize (e.g. a per-user pool of API keys for providers that use real keys, alongside the existing cookie-based Gemini pool). This is the highest-leverage "big bet" on this list — most other ideas below get easier once this exists.
- 🟡 **Bring-your-own-API-key option**, as a lighter first step toward the above: let a user attach an OpenAI/Anthropic API key to an app instead of (or as a fallback behind) the Gemini cookie pool, without a full provider abstraction yet.

## Gateway features

- 🟡 **Streaming (SSE) support** on `/v1/chat/completions` — WebAI-to-API's `/v1/temporary/chat/completions` already supports streaming; the gap is entirely on the Laravel side (`ChatCompletionGateway` currently only handles buffered responses).
- 🟡 **Tool/function-calling passthrough** — pass OpenAI-style `tools`/`tool_choice` through to Gemini and shape the response back into the OpenAI tool-call format, so existing OpenAI-SDK-based agent code works against this gateway with minimal changes.
- 🟡 **Per-model rate limits/quotas**, not just per-token — useful once multiple providers/models have very different cost profiles.
- 🟢 **Webhooks on usage events** — e.g. notify a user's own endpoint when their account pool is fully exhausted, or when daily quota is close to being hit. Natural pairing with the health-check job above.
- 🟢 **Fine-grained token scopes** — e.g. a token that can only call one specific app, or is read-only against `/v1/models`. Today one token = full access to its app.

## RAG

- 🟢 **URL ingestion** — fetch + strip HTML, a natural next source type now that PDF/DOCX (shipped 2026-08-03) cover the common document formats.
- 🟢 **OCR for scanned/image-only PDFs** — the current PDF extractor only pulls an existing text layer; a scanned document with no text layer comes back empty and the upload is marked `failed`. Tesseract or a cloud OCR API would close this gap.
- 🟡 **Hybrid search** — combine the existing pgvector cosine search with plain keyword/full-text search (Postgres `tsvector` is right there) and merge-rank results; pure embedding search misses exact-term matches (product codes, names) surprisingly often.
- 🟢 **Chunk citations in the response** — since `RagRetriever` already knows which chunks were used, threading that through to the final response (even just as metadata) would let a caller show "sourced from: doc X, doc Y."
- 🔴 **Team/shared knowledge bases** — explicitly deferred in v1 (KBs are owned by one user). Needs a real permissions model, not just a schema change.
- 🟢 **Configurable chunk size/overlap per knowledge base**, rather than the current hardcoded ~800/100 split in `TextChunker`.

## Billing & limits

- 🔴 **Usage-based billing.** Explicitly out of scope for v1. The `usage_records` table already has everything needed to compute cost (tokens, model, timestamps) — the actual gap is a billing provider integration (Stripe metered billing is the obvious fit) and the account/invoicing UI around it.
- 🟢 **Spend caps without full billing** — a simpler middle ground: let a tenant set a hard monthly token budget and hard-stop the gateway once it's hit, with no money changing hands. Much smaller than full billing, and a natural stepping stone toward it.

## Team, admin & security

- 🟡 **SSO (SAML/OIDC)** — mentioned as out-of-scope-for-v1 in the plan; likely to come up the moment a B2B customer asks for it.
- 🟢 **Audit log export** — usage_records plus an actual audit trail of admin actions (invites created, tokens revoked, roles changed) as a downloadable CSV/JSON.
- 🟡 **Granular RBAC** — today it's a flat owner/admin/member. A real permissions model (e.g. "can manage tokens but not accounts") would matter for larger teams.
- 🟢 **Cookie/secret rotation tooling** — a documented, scripted way to rotate the Laravel `APP_KEY` (which the `encrypted:array` cast for Gemini cookies depends on) without locking every existing account out.

## Developer experience

- 🟡 **Public OpenAPI spec + interactive docs** for the `/v1/*` gateway surface, generated from the actual routes rather than hand-maintained — makes the "OpenAI-compatible" claim verifiable and gives external integrators something better than reading this repo's source.
- 🟢 **Official JS/Python SDK wrappers** — even a thin one (just base URL + auth header pre-wired) removes friction for anyone trying to point existing OpenAI-SDK-based code at this gateway.
- 🟢 **A CLI** for common admin tasks (create invite, rotate a token, check pool health) — useful for scripting and for anyone who'd rather not click through the dashboard.

## Scale & ops

- 🔴 **Horizontal scaling of WebAI-to-API.** Currently single-instance by design (its auth coordination lock is process-bound). Needed the moment request volume outgrows one container — requires the `auth_lock_backend` work above plus load-testing the ephemeral-client-per-request path under concurrency.
- 🟡 **Structured tracing/observability** — request IDs already exist (`RequestIDMiddleware` in WebAI-to-API); wiring them through the Laravel side too and shipping both to a real tracing backend would make debugging a slow/failed gateway call much faster than log-grepping.
- 🟢 **Alerting on account-pool exhaustion tenant-wide** (pairs with the admin JSON API idea above) — right now a fully-exhausted pool is only visible if someone happens to look at the dashboard.

## How to use this list

When it's time to plan the next phase: pick from here based on what's actually hurting right now (check [10-troubleshooting.md](10-troubleshooting.md) and real usage patterns first), not just what looks interesting. A good next slice is usually one 🔴 big bet *or* a small cluster of 🟢/🟡 items that reinforce each other — e.g. "health-check job + proactive expiry warning + admin JSON API" is a coherent, shippable chunk on its own.
