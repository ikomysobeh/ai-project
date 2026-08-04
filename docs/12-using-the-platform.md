# Using the Platform

This is the user-facing guide: how to actually operate TokenForge once it's running — creating apps, generating tokens, adding Gemini accounts, wiring up RAG, and calling the gateway endpoint with the right parameters. If you're looking for how the *codebase* is built instead, start at [01-architecture.md](01-architecture.md).

## Core concepts, in one paragraph

A **tenant** (your workspace) has **users** (owner/admin/member). A user creates an **app** (a name + a default Gemini model + optionally one attached knowledge base), generates one or more **API tokens** for it, and calls the gateway with `Authorization: Bearer <token>`. The gateway answers using whichever of *that user's own* **Gemini accounts** is healthy, optionally enriching the prompt from the app's attached **knowledge base** (RAG) first.

## 1. Get your workspace ready

### Sign up or accept an invite

- **New workspace**: go to `/register` — this creates a brand-new tenant and makes you its **owner**. You'll fill in a workspace name, your name, email, and password.
- **Joining an existing workspace**: an owner/admin sends you an invite link (`/invite/{token}`, valid 7 days, single-use) from **Team & Invites**. Opening it shows you the workspace name and the role you're being given, then lets you set a password.

### Add at least one Gemini account

Nothing works until you have at least one **active** Gemini account — go to **Gemini Accounts** in the sidebar → **Add Gemini account**.

| Field | What to put |
|---|---|
| Label | Any name you'll recognize later (e.g. `gemini-acct-01`) |
| `__Secure-1PSID` | Copied from your browser's cookies for `gemini.google.com` |
| `__Secure-1PSIDTS` | Same — copied at the same moment |

**Read this before you paste anything**: `__Secure-1PSIDTS` is a short-lived, rotating cookie. If you leave the source browser tab open, switch Google accounts in the same window, or wait too long between copying and pasting, the value is already stale by the time you submit it — the account will show `expired` immediately, even though nothing is broken. Use a **fresh private/incognito window per account**, grab both cookie values from the same page load, paste them in right away, then close that window. Full explanation: [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md#cookie-fragility-the-1-gotcha).

If validation fails, the dashboard now shows you the **real reason** — hover the status pill, or read the toast. If it mentions `SECURE_1PSIDTS`, it's the rotation issue above; anything else is worth investigating directly (check `docker compose logs webai`).

You can have up to **5 Gemini accounts per user**. The gateway rotates through your *own* accounts only — there's no shared fallback pool, so if all of yours are down, your requests fail visibly rather than silently borrowing someone else's.

## 2. Create an app

**Apps** → **New App**.

| Field | Meaning |
|---|---|
| Name | Just a label, shown everywhere else in the dashboard |
| Default model | Used when a gateway call doesn't specify its own `model` |
| Knowledge base | Optional — attach one to enable RAG for every call this app receives |

> The model dropdown (and `GET /v1/models`) only ever lists plain `gemini-*` models — `playwright/...` and `atlas/...` variants are filtered out everywhere in this UI/API, since the gateway's chat path always rejects them with a 400. That only matters if you're calling the gateway directly and pass a hand-typed `model` override in the request body instead of picking from the dropdown — stick to a plain id like `gemini-3-flash` or `gemini-3-pro` there too.

## 3. Generate an API token

**API Tokens** → **Generate token**.

| Field | Meaning |
|---|---|
| App | Which app this token authenticates as |
| Token name | A label (e.g. "production key") |
| Daily quota | Max **requests** per day for this token (not a token-count budget — see [Rate limits & quotas](#rate-limits--quotas) below) |
| Expires | `Never`, `30 days`, `90 days`, `1 year`, or a custom date |

The raw token (`tf_...`) is shown right after creation — copy it, or don't worry if you close the dialog: it's stored encrypted, so you can come back to the **API Tokens** page and hit **View** on that row anytime to see or copy it again. (Tokens generated before this feature shipped don't have a `View` button — those older raw values were only ever hashed, not stored, so they genuinely can't be recovered; revoke and regenerate those if you've lost the copy.)

**Revoking** a token is instant — the very next gateway call using it gets rejected, no caching delay.

A token whose expiration date has passed behaves exactly like a revoked one for authentication purposes (the gateway rejects it), but keeps its own `expired` status in the list so you can tell the two apart at a glance.

> The backend also supports a per-token `rate_limit` override (requests/minute, default 60) — it's not exposed in the "Generate token" form yet, only `daily_quota` is. If you need a non-default rate limit today, it can be set directly via `POST /api/apps/{id}/tokens` with a `rate_limit` field.

## 4. Add a knowledge base (RAG) — optional

**Knowledge (RAG)** → **New knowledge base** → give it a name → **Upload** a document.

- **`.txt`**, **`.md`**, **`.pdf`**, or **`.docx`** (modern Word — legacy binary `.doc` isn't supported), up to **20 MB**.
- After upload the document goes `indexing` → `ready` (or `failed`) — the text is extracted (for PDF/Word, that means pulling the actual text content out of the file), chunked (~800 tokens, ~100 overlap), each chunk embedded via Ollama, and stored for retrieval. This runs in the background; give it a few seconds for a small file, longer for a large PDF.
- A `failed` status usually means the file had no extractable text — most commonly a **scanned/image-only PDF** with no text layer (this pipeline doesn't do OCR). Try a text-based PDF or export it to `.txt`/`.md` instead.
- Attach the knowledge base to an app from the **Apps** page. Once attached and `ready`, every gateway call to that app automatically retrieves the most relevant chunks for the user's question and prepends them as context — no extra parameter needed on the request itself.

## 5. Call the gateway

This is the actual product: an OpenAI-compatible endpoint your own code (or any OpenAI-client library) can call.

```
POST /v1/chat/completions
Authorization: Bearer <your token>
Content-Type: application/json
```

### Request body

| Field | Type | Required | Notes |
|---|---|---|---|
| `messages` | array of `{role, content}` | **yes** | `role` is `user`/`assistant`/`system`. `content` is either a plain string, or an array of content parts (see [Multimodal / file uploads](#multimodal--file-uploads) below). |
| `model` | string | no | Defaults to the app's default model if omitted. Must be a plain `gemini-*` id — see the callout above. |
| `stream` | boolean | no | `true` for Server-Sent Events streaming. Default `false`. |
| `tools` | array of OpenAI-style tool defs | no | See [Tool calling](#tool-calling) below. |
| `tool_choice` | any | no | Accepted for OpenAI-client compatibility; not independently enforced beyond whether `tools` is present. |
| `gem` | string | no | A Gemini Gem's id or name, used as a system-prompt persona for this call. |
| `conversation_id` | — | **not supported** | Omit this entirely. Every call through this gateway is stateless — there's no server-side conversation thread. If your client library adds this field automatically, make sure it's left out or set to `null`. |

Everything you send is passed straight through to Gemini via WebAI-to-API — the Laravel gateway doesn't reshape your payload beyond resolving `model` and injecting RAG context, so the request/response contract is the same OpenAI chat-completions shape you'd use against any other provider.

### Example

```bash
curl https://your-tokenforge-host/v1/chat/completions \
  -H "Authorization: Bearer tf_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "messages": [{"role": "user", "content": "Summarize this in one sentence: TokenForge is a Gemini gateway."}]
  }'
```

```js
const res = await fetch('https://your-tokenforge-host/v1/chat/completions', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${process.env.TOKENFORGE_KEY}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    messages: [{ role: 'user', content: 'Hello!' }],
  }),
});
const data = await res.json();
console.log(data.choices[0].message.content);
```

```python
# Any OpenAI-compatible client works — just point base_url at your gateway.
from openai import OpenAI

client = OpenAI(base_url="https://your-tokenforge-host/v1", api_key="tf_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx")

resp = client.chat.completions.create(
    model="gemini-3-flash",
    messages=[{"role": "user", "content": "Hello!"}],
)
print(resp.choices[0].message.content)
```

### Response shape

Standard OpenAI chat-completion object:

```json
{
  "id": "chatcmpl-1234567890",
  "object": "chat.completion",
  "created": 1234567890,
  "model": "gemini-3-flash",
  "choices": [
    {
      "index": 0,
      "message": { "role": "assistant", "content": "..." },
      "finish_reason": "stop"
    }
  ],
  "usage": { "prompt_tokens": 21, "completion_tokens": 27, "total_tokens": 48 }
}
```

> **`usage` is an estimate, not a real count.** Gemini's web interface (unlike its official API) never reports actual token counts, so this gateway can't relay real ones either — instead it estimates `prompt_tokens`/`completion_tokens` itself from the actual request/response text, using the same BPE tokenizer OpenAI's models use (`cl100k_base`). That's a reasonable, consistent approximation for comparing usage across apps/tokens/time, but it won't exactly match what Gemini's own (non-public) tokenizer would have counted — don't treat it as billing-grade. The per-token daily quota/rate limit described below are still **request counts**, not token counts, for the same reason.

If the response included generated images, video, or audio, they show up as an `artifacts` array on the choice: `choices[0].artifacts`, each `{type, provider, title?, url?, ...}`.

### Multimodal / file uploads

`content` can be an array of parts instead of a plain string:

```json
{
  "role": "user",
  "content": [
    { "type": "text", "text": "What's in this file?" },
    {
      "type": "file",
      "file": {
        "filename": "notes.txt",
        "file_data": "data:text/plain;base64,SGVsbG8gd29ybGQ="
      }
    }
  ]
}
```

`file_data` must be a base64 **data URL** (`data:<mime>;base64,...`) — remote URLs and file paths aren't accepted. Files are request-scoped (not persisted between calls); text/file interleaving in your message isn't preserved exactly, but the model sees both.

### Tool calling

Gemini's web interface has no native function-calling API, so this is simulated via prompting: when you send `tools`, the gateway builds a system instruction describing them and asks the model to reply with a specific JSON shape when it wants to call one. If the model does, the response comes back already reshaped into the standard OpenAI tool-call format:

```json
{
  "choices": [{
    "message": {
      "role": "assistant",
      "content": null,
      "tool_calls": [{
        "id": "call_1234567890",
        "type": "function",
        "function": { "name": "get_weather", "arguments": "{\"city\":\"Paris\"}" }
      }]
    },
    "finish_reason": "tool_calls"
  }]
}
```

This is best-effort, not a hard guarantee the way native function calling is — it works well for straightforward single tool calls, but don't rely on it for complex multi-step tool orchestration in one turn. When `stream: true` is combined with `tools`, the response is still generated as one buffered call and then replayed as SSE chunks — it isn't truly incrementally streamed in that case.

### Listing available models

```
GET /v1/models
Authorization: Bearer <your token>
```

Returns the OpenAI-style `{ "object": "list", "data": [{ "id": "gemini-3-flash", "object": "model", ... }, ...] }` catalog — already filtered down to only the plain `gemini-*` ids this gateway can actually serve (the exact set can still vary with your Gemini account's tier).

### Errors you'll actually see

| Status | Meaning | What to do |
|---|---|---|
| `400` | Bad request — e.g. an unsupported model prefix, or `conversation_id` was included | Fix the request body; see the callouts above |
| `401` | Missing, invalid, revoked, or expired token | Check the token's status on the **API Tokens** page |
| `429` | Per-token rate limit (req/min) or daily quota exceeded | Wait, or raise the token's limits |
| `502` | WebAI-to-API itself is unreachable | An ops/deployment problem — check `docker compose logs webai` |
| `503` | No active Gemini accounts in your pool (or all of them just failed) | Add or re-authenticate an account under **Gemini Accounts** |

## 6. Try it without writing code first

**Playground** in the sidebar runs the exact same pipeline as a real `POST /v1/chat/completions` call, authenticated by your dashboard session instead of a token — pick an app, pick a model, send a message, and see the raw response plus the equivalent `curl` command. Good for confirming an app/account/KB setup works before wiring up real client code.

## 7. Team & admin

- **Team & Invites** (owner/admin only) — see members, create invite links with a role attached.
- **Overview** (owner/admin only) — tenant-wide usage and error stats, per-member breakdown.
- Roles: **Owner** (full control), **Admin** (sees/manages everything except can't be demoted by non-owners), **Member** (their own apps/tokens/accounts/knowledge bases only).

## Rate limits & quotas

Two independent, per-token checks, both enforced **before** your request reaches Gemini:
- **Rate limit** — requests per rolling 60 seconds (default 60).
- **Daily quota** — requests per calendar day (default 1000), reset at midnight UTC.

Both are **request counts**, not token counts — that's independent of the estimated `usage` numbers described above, and unaffected by them.

## Quick troubleshooting

- **A newly-added Gemini account shows `expired` immediately** → almost always a stale `__Secure-1PSIDTS`, not a real problem. See [step 1](#add-at-least-one-gemini-account) above.
- **Gateway calls 400 with a model error** → a per-request `model` override in your request body is pointed at a `playwright/...` or `atlas/...` model id (the dropdown itself won't let you pick one). Use a plain `gemini-*` id instead.
- **Gateway calls 503 `no active accounts`** → every account in your personal pool is `expired`/`cooling_down`. There's no shared fallback — go add or re-authenticate one.
- **Token counts look approximate/slightly off** → expected; they're estimated from the actual request/response text (OpenAI's `cl100k_base` tokenizer), since Gemini's web interface never reports real counts. Good for relative comparison, not exact.
- **Full architecture/troubleshooting for developers**: see [10-troubleshooting.md](10-troubleshooting.md).
