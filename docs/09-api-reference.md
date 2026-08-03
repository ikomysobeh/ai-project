# API Reference

## Gateway (`/v1/*`) — token auth, external clients

Registered outside the `/api` prefix on purpose (see [03-backend-laravel.md](03-backend-laravel.md#routes)), so an OpenAI-compatible client SDK can point its base URL directly at `/v1`.

| Method | Path | Controller | Middleware |
|---|---|---|---|
| POST | `/v1/chat/completions` | `Api\GatewayController@chatCompletions` | `api.token`, `api.limits` |
| GET | `/v1/models` | `Api\GatewayController@models` | `api.token` |

Auth: `Authorization: Bearer <token>` (a raw token generated via the console, shown once). Full pipeline: [07-gateway-and-rag.md](07-gateway-and-rag.md).

## Management API (`/api/*`) — Sanctum session auth

All under `api` middleware group + `SetTenantContext` appended; per-route `auth:sanctum` unless noted.

### Auth & invites
| Method | Path | Controller | Middleware |
|---|---|---|---|
| POST | `/api/auth/signup` | `Api\AuthController@signup` | `throttle:6,1` |
| POST | `/api/auth/login` | `Api\AuthController@login` | `throttle:6,1` |
| POST | `/api/auth/logout` | `Api\AuthController@logout` | `auth:sanctum` |
| GET | `/api/me` | `Api\AuthController@me` | `auth:sanctum` |
| POST | `/api/invites` | `Api\InviteController@store` | `auth:sanctum`, `role:owner,admin` |
| POST | `/api/invites/{token}/accept` | `Api\InviteController@accept` | `throttle:6,1` (public) |

### Apps & tokens
| Method | Path | Controller | Middleware |
|---|---|---|---|
| GET | `/api/apps` | `Api\AppController@index` | `auth:sanctum` |
| POST | `/api/apps` | `Api\AppController@store` | `auth:sanctum` |
| DELETE | `/api/apps/{app}` | `Api\AppController@destroy` | `auth:sanctum` |
| POST | `/api/apps/{app}/attach-kb` | `Api\AppController@attachKnowledgeBase` | `auth:sanctum` |
| GET | `/api/apps/{app}/tokens` | `Api\ApiTokenController@index` | `auth:sanctum` |
| POST | `/api/apps/{app}/tokens` | `Api\ApiTokenController@store` | `auth:sanctum` |
| DELETE | `/api/tokens/{token}` | `Api\ApiTokenController@destroy` | `auth:sanctum` |

### Gemini account pool
| Method | Path | Controller | Middleware |
|---|---|---|---|
| GET | `/api/accounts` | `Api\UpstreamAccountController@index` | `auth:sanctum` |
| POST | `/api/accounts` | `Api\UpstreamAccountController@store` | `auth:sanctum` |
| POST | `/api/accounts/{account}/reauth` | `Api\UpstreamAccountController@reauth` | `auth:sanctum` |
| POST | `/api/accounts/{account}/test` | `Api\UpstreamAccountController@test` | `auth:sanctum` |

Body for `store`/`reauth`: `{label?, secure_1psid, secure_1psidts}`. See [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md).

### Knowledge bases / RAG
| Method | Path | Controller | Middleware |
|---|---|---|---|
| GET | `/api/knowledge-bases` | `Api\KnowledgeBaseController@index` | `auth:sanctum` |
| POST | `/api/knowledge-bases` | `Api\KnowledgeBaseController@store` | `auth:sanctum` |
| GET | `/api/knowledge-bases/{knowledgeBase}/documents` | `Api\DocumentController@index` | `auth:sanctum` |
| POST | `/api/knowledge-bases/{knowledgeBase}/documents` | `Api\DocumentController@store` | `auth:sanctum` |
| DELETE | `/api/documents/{document}` | `Api\DocumentController@destroy` | `auth:sanctum` |

### Playground
| Method | Path | Controller | Middleware |
|---|---|---|---|
| POST | `/api/playground/{app}/send` | `Console\PlaygroundController@send` | `auth:sanctum` |

Runs the exact same `ChatCompletionGateway::run()` pipeline as the real gateway, session-authenticated instead of Bearer-token-authenticated, `tokenId: null` (so it doesn't count against any token's quota).

## Console pages (`/console/*`) — Inertia, read-only page loads

All mutations for these pages go through the `/api/*` routes above via `fetch()`; these routes only render the initial page + props.

| Method | Path | Controller | Middleware |
|---|---|---|---|
| GET | `/dashboard` | `Console\DashboardController@index` | `auth`, `verified` |
| GET | `/console/apps` | `Console\AppsController@index` | `auth`, `verified` |
| GET | `/console/tokens` | `Console\TokensController@index` | `auth`, `verified` |
| GET | `/console/accounts` | `Console\AccountsController@index` | `auth`, `verified` |
| GET | `/console/knowledge` | `Console\KnowledgeController@index` | `auth`, `verified` |
| GET | `/console/playground` | `Console\PlaygroundController@index` | `auth`, `verified` |
| GET | `/console/admin` | `Console\AdminController@index` | `auth`, `verified`, `role:owner,admin` |
| GET | `/console/team` | `Console\TeamController@index` | `auth`, `verified`, `role:owner,admin` |

## Web / auth / settings pages

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Marketing splash page. |
| GET | `/invite/{token}` | Public invite-preview page (`InviteAcceptController@show`). |
| GET/POST | `/login`, `/register`, `/forgot-password`, `/reset-password`, `/confirm-password`, `/two-factor-challenge` | Fortify-driven auth pages. |
| GET/PATCH/DELETE | `/settings/profile` | Own profile. |
| GET/PUT | `/settings/security` | Password + 2FA/passkeys. |
| GET | `/settings/appearance` | Theme preference. |

## Internal: WebAI-to-API (`webai:6969`, not exposed publicly)

Never called by anything except ai-bridge's own [`WebAiClient`](../ai-bridge/app/Services/WebAiClient.php). Listed here for completeness when debugging.

| Method | Path | Notes |
|---|---|---|
| POST | `/v1/temporary/chat/completions` | **The one TokenForge actually uses.** Accepts `X-Gemini-1PSID`/`X-Gemini-1PSIDTS` override headers. Stateless, no conversation persistence. See [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md). |
| GET | `/v1/models` | Model catalog across registered providers (also called directly by `Api\GatewayController@models`). |
| POST | `/v1/chat/completions` | WebAI-to-API's own primary path — single process-wide account, **not used by TokenForge's rotation**. |
| GET | `/v1/gems` | List Gemini Gems for the singleton account. |
| GET/DELETE | `/v1/conversations`, `/v1/conversations/{id}` | Manage locally persisted (SQLite) conversation snapshots — singleton-account only. |
| POST | `/translate` | Legacy endpoint for a browser-extension integration; unrelated to TokenForge. |
| GET | `/v1/auth/status`, POST `/v1/auth/login` | Singleton-account auth status / interactive Playwright login trigger. |
| GET | `/health`, `/ready` | Liveness/readiness. **`/ready` does not check Gemini auth validity** — don't use it to detect account health. |
| GET | `/v1/runtime/status` | Diagnostics (engine status, per-session metrics, cached auth status). |
| `/ui/*` | Admin dashboard — explicitly excluded from the public API contract; don't expose this port publicly without separately securing it. |
