# Gemini Accounts & WebAI-to-API Integration

This is the piece of the system most likely to confuse a new developer, so it gets its own doc. Read this before touching anything account- or gateway-related.

## Why cookies instead of an API key

TokenForge talks to Gemini through **WebAI-to-API** (`WebAI-to-API/`), a cloned FastAPI service (upstream: a "browser-native AI runtime" built around the `gemini-webapi` library) that authenticates as a real signed-in Google account — either by driving a real browser via Playwright, or by replaying that account's session cookies. There is no official Gemini API key involved anywhere in this product; this was a deliberate MVP decision (see [`mvp-scope.md`](../mvp-scope.md) §2: *"Adding a Gemini account: paste cookies. No guided-login automation in v1."*).

**License correction**: the original planning docs flagged WebAI-to-API as AGPLv3 and asked for manager sign-off before shipping. The actual vendored version is licensed **Apache-2.0** (`WebAI-to-API/LICENSE`, `pyproject.toml`); its changelog notes a migration to Apache-2.0 as the sole license. This blocker no longer applies, but it's worth knowing the history didn't quite match the code.

## Two completely different auth paths inside WebAI-to-API

WebAI-to-API supports two unrelated ways of authenticating to Gemini, and it's important not to mix them up:

### 1. The singleton/bootstrap path (not used by TokenForge's account pool)

One globally-configured Gemini account, loaded once at process start from `config.conf` or `runtime/auth/gemini.json`, held in a single process-wide `MyGeminiClient` / `SessionRegistry`. This is what powers WebAI-to-API's own `/v1/chat/completions`, `/v1/gems`, `/v1/conversations`, the Playwright backend, and its interactive browser login flow (`AuthManager`, `GeminiAuthStrategy`, `verify_login.py`). It's designed for "one server, one Gemini account," which is exactly what TokenForge does **not** want (every tenant user brings their own accounts).

### 2. The per-request ephemeral override path (what TokenForge actually uses)

This is a small patch layered on top of the upstream project, marked in the code as `# --- LOCAL PATCH (not upstream) ---`, in exactly two files:

- [`WebAI-to-API/src/app/endpoints/chat.py`](../WebAI-to-API/src/app/endpoints/chat.py) — the `POST /v1/temporary/chat/completions` route reads two optional headers, `X-Gemini-1PSID` and `X-Gemini-1PSIDTS`. If **both** are present, it attaches them to the request as `_gemini_cookie_override`. If either is missing, the override is silently ignored.
- [`WebAI-to-API/src/app/services/providers/gemini/temporary_chat.py`](../WebAI-to-API/src/app/services/providers/gemini/temporary_chat.py) — `_build_ephemeral_gemini_client()` builds a **brand-new, one-off `MyGeminiClient`** from those cookies (plus the configured proxy), calls `client.init(verbose=True, auto_refresh=False)`, and checks `account_status.name == "AVAILABLE"` — raising 401 if not. The client is closed right after the call (or from inside the SSE generator's `finally`, for streaming — there's a long comment in that file explaining exactly why the close can't be folded into the outer function's `finally` without killing the stream before it starts).

This override is **only** wired into `/v1/temporary/chat/completions`, because it's the one Gemini WebAPI path with no `SessionRegistry`/conversation-persistence machinery to fight with. On this endpoint, `conversation_id` is rejected outright, and every call is stateless (`temporary=True` — not saved to Gemini history, no SQLite snapshot).

**This mechanism is undocumented anywhere in WebAI-to-API's own `README.md`/`docs/*.md`** — it exists only as code comments in those two files. Treat this document, and the code comments themselves, as the source of truth for it. If you ever pull upstream WebAI-to-API changes into this clone, **do not lose these two patched regions** — nothing else marks them as deliberate deviations.

### Why Laravel calls `/v1/temporary/chat/completions`, not `/v1/chat/completions`

[`app/Services/WebAiClient.php`](../ai-bridge/app/Services/WebAiClient.php) is the only place in ai-bridge that calls WebAI-to-API, and its docblock explains this directly: the main `/v1/chat/completions` path holds one Gemini session per process with no per-request override seam. The `/temporary/` path has exactly the override seam TokenForge needs. Not getting Gemini's own conversation threading is a non-issue anyway — the gateway itself is stateless (callers resend full message history every call, same as real OpenAI).

## The account lifecycle

Each `upstream_accounts` row ([schema](05-database-schema.md#gemini-account-pool)) holds one user's `{psid, psidts}` cookie pair, encrypted at rest, plus a `status`: `active` / `cooling_down` / `expired`.

**Adding an account** ([`Api\UpstreamAccountController::store()`](../ai-bridge/app/Http/Controllers/Api/UpstreamAccountController.php)) — capped at 5 per user. On submit, the cookies are validated with a **real live call** through the ephemeral override path (a trivial "ping" chat completion) before the account is even considered active — a bad paste is caught immediately instead of on the account's first real use.

**Re-authenticating** (`reauth()`) and **testing** (`test()`) run the same live validation.

**Rotation at request time** ([`ChatCompletionGateway::run()`](../ai-bridge/app/Services/Gateway/ChatCompletionGateway.php), full detail in [07-gateway-and-rag.md](07-gateway-and-rag.md)) — picks the least-recently-used `active` account from *that request's own user's* pool (no shared/tenant-wide fallback — this is a deliberate design commitment, see `mvp-scope.md` §2). On a 401 it marks the account `expired` (with the real failure reason — see below) and tries the next; on a 429 it marks it `cooling_down` for 5 minutes and tries the next; any other error is returned immediately, since it would fail identically on every account in the pool.

### Surfacing the real failure reason (fixed 2026-08-03)

Originally, every validation failure collapsed into a bare `status: expired` with no way to tell *why* — a genuinely stale cookie looked identical to a network problem or an IP-level block. This was fixed: `UpstreamAccountController::validateCookies()` now pulls the real message out of WebAI-to-API's response body (`detail`, or `error.message`) and stores it on `upstream_accounts.last_error`. `ChatCompletionGateway` does the same when it marks an account expired mid-request. The dashboard shows it as a toast on add/re-auth/test, and as a tooltip on the "expired" status pill (see [04-frontend-react.md](04-frontend-react.md)).

## Cookie fragility — the #1 gotcha

**`__Secure-1PSIDTS` is a rotating, short-lived Google cookie.** This is not a bug in this codebase — it's inherent to how Gemini's cookie-based auth works, and it's explicitly called out as a known risk in [`mvp-scope.md`](../mvp-scope.md) §11: *"Cookie fragility — Gemini cookies expire often; rotation + health checks are mandatory, not optional."*

What this looks like in practice: you add your first Gemini account right after a fresh login and it validates fine (`active`). You then try to add a second account, or re-authenticate an existing one, and it comes back `expired` immediately — even though you just copied the cookies. The `last_error` message (see above) will usually say something like:

> *"Failed to initialize client... SECURE_1PSIDTS could get expired frequently, please make sure cookie values are up to date."*

That message comes straight from the `gemini_webapi` library itself (pinned at exactly `2.0.0` — see `WebAI-to-API/poetry.lock`), raised from `get_access_token()` when none of the cookie candidates it tries can authenticate.

Why this happens: `__Secure-1PSIDTS` can be invalidated the moment it's reused elsewhere, or as soon as the browser tab it came from makes another request to Gemini. If you leave the source tab open, switch Google accounts within the same browser profile, or simply take too long between copying and pasting, the value is already stale by the time you submit it. WebAI-to-API's `MyGeminiClient` is deliberately built with `auto_refresh=False` for these ephemeral, per-request clients (unlike the singleton path), so there's no in-process retry/refresh to paper over a stale cookie — it just fails immediately, which is actually the correct, honest behavior here.

**How to avoid it** (also in [02-getting-started.md](02-getting-started.md#how-to-get-gemini-cookies-for-an-account)):
- Use a **fresh private/incognito window per account** — sign in to just that one Google account.
- Copy `__Secure-1PSID` and `__Secure-1PSIDTS` **from the same page load, at the same moment**.
- Paste them into the dashboard **immediately**, then close that window.
- Never use Google's "switch account" feature inside one browser tab to grab multiple accounts' cookies — this is the single most common way to accidentally grab an already-rotated value.

WebAI-to-API's own docs independently confirm the same guidance for its singleton login path: obtain cookies from a separate/private browsing session and close it as soon as possible.

## Configuration reference

`WebAI-to-API/config.conf` (copied from `config.conf.example`) — sections relevant to the account-pool integration:

| Section.key | Purpose |
|---|---|
| `[Gemini] backend` | `webapi` (default, used by the ephemeral override path) or `playwright`. |
| `[Proxy] http_proxy` | Outbound proxy for reaching Gemini — also consumed directly by the ephemeral client construction in `temporary_chat.py`. Useful if Google starts returning 403s from your server's IP. |
| `[Playwright] auth_lock_backend` | Only `in_memory` is implemented — relevant only to the singleton bootstrap path, not the ephemeral per-account path, but matters if you ever scale WebAI-to-API to multiple workers. |

None of `[Gemini] __Secure-1PSID`/`__Secure-1PSIDTS` or `runtime/auth/gemini.json` are used by TokenForge's per-user account pool — those are singleton-path configuration and can be left blank. TokenForge's cookies live entirely in Postgres (`upstream_accounts.cookies_encrypted`), sent per-request as headers.

## Health/readiness caveat

WebAI-to-API's `GET /ready` endpoint does **not** validate Gemini authentication at all — a node is "structurally ready" even if every account's cookies are expired. Don't rely on it to detect account health; that's what the dashboard's on-demand test/validate calls are for (and there is, notably, no periodic background health-check job — see [03-backend-laravel.md](03-backend-laravel.md#jobs)).
