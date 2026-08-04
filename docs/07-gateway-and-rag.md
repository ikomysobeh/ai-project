# The Gateway Pipeline & RAG

## The gateway request, step by step

Entry point: `POST /v1/chat/completions` (also `GET /v1/models`), registered outside the `/api` prefix — see [09-api-reference.md](09-api-reference.md#gateway-v1). Middleware runs in this order:

### 1. `AuthenticateApiToken` (alias `api.token`)

[app/Http/Middleware/AuthenticateApiToken.php](../ai-bridge/app/Http/Middleware/AuthenticateApiToken.php)

- Reads the `Bearer` token, hashes it (sha256 via `ApiToken::hash()`), checks Redis (`api_token:{hash}`) before touching Postgres.
- On a cache miss, looks up `ApiToken::withoutGlobalScopes()->where('token_hash', $hash)` (tenant scope not yet set — the hash itself is the auth boundary), validates it's not revoked and not expired, caches the hit for 300s (or a `'null'` sentinel for 30s on a miss, so a bad/replayed token can't hammer the database).
- Sets `TenantContext` from the token's tenant, and stashes `gateway_app_id` / `gateway_token_id` / `gateway_rate_limit` / `gateway_daily_quota` as request attributes for the next middleware and controller to use.
- Revocation is instant: `ApiTokenController::destroy()` explicitly deletes the Redis cache entry on revoke, so the very next call re-hits the database and sees `revoked_at`.

### 2. `EnforceApiTokenLimits` (alias `api.limits`, chat-completions route only)

[app/Http/Middleware/EnforceApiTokenLimits.php](../ai-bridge/app/Http/Middleware/EnforceApiTokenLimits.php)

Two independent, per-token `RateLimiter` checks, both enforced **before** the request reaches the gateway service or any upstream call:
- `gateway-rate:{tokenId}` — 60-second window, max = the token's own `rate_limit` (default 60/min).
- `gateway-quota:{tokenId}:{Y-m-d}` — 86400-second window, max = the token's own `daily_quota` (default 1000/day).

Either one exceeded → `429` immediately, no upstream call made.

### 3. `GatewayController::chatCompletions()` → `ChatCompletionGateway::run()`

[app/Services/Gateway/ChatCompletionGateway.php](../ai-bridge/app/Services/Gateway/ChatCompletionGateway.php) — shared by both the real token-authenticated gateway *and* the session-authenticated console Playground (`Console\PlaygroundController::send()`, with `tokenId: null`), so both run identical logic.

```php
run(Application $app, array $payload, ?int $tokenId): array
```

1. **Resolve model** — `$payload['model'] ?: $app->default_model`.
2. **RAG augmentation** — if the app has a knowledge base attached *and* it's `status === 'ready'`:
   - Extract the last `role: user` message's text.
   - `RagRetriever::topChunks($app->knowledgeBase, $question)` — embed the question, vector search, return the top chunks.
   - If any came back, prepend a synthetic `role: system` message joining all chunks (`\n\n---\n\n` separated) and mark `used_rag = true`.
3. **Pick an account** — query `UpstreamAccount::where('user_id', $app->user_id)->where('status', 'active')`, ordered `last_used_at IS NOT NULL, last_used_at ASC` (never-used accounts first, then oldest-used) — i.e. **LRU across this app owner's own pool only**. No accounts → log `no_active_accounts`, return `503`.
4. **Rotation loop** — for each candidate account in order:
   - `WebAiClient::chatCompletions($forwardPayload, $account->cookies_encrypted)` → WebAI-to-API's `/v1/temporary/chat/completions` with `X-Gemini-1PSID`/`X-Gemini-1PSIDTS` headers (see [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md)).
   - `ConnectionException` (WebAI-to-API unreachable) → log `upstream_unreachable`, return `502` **immediately** — a network-down condition would fail identically for every account, so there's no point rotating through the rest.
   - **Success** → mark the account `active` + `last_used_at = now()`; estimate `prompt_tokens`/`completion_tokens` via [`TokenEstimator`](../ai-bridge/app/Services/Gateway/TokenEstimator.php) from the actual request messages and response text (WebAI-to-API's own `usage` is always `{0,0,0}` — Gemini's web interface never reports real counts — so it's overwritten rather than trusted); log a `usage_record` with those numbers; return the response with `usage` patched to the estimate.
   - **401** → `$account->markExpired($reason)` (reason pulled from the response's `detail`/`error.message`), `continue` to the next account.
   - **429** → `$account->markCoolingDown()` (5-minute cooldown), `continue` to the next account.
   - **Any other non-2xx** → treated as a real request/server error that would fail identically on every account — log `upstream_error`, return that status/body **immediately**, no further rotation.
5. **All accounts exhausted** — log `all_accounts_unavailable`, return `503`. There is no shared/cross-user fallback pool by design (`mvp-scope.md` §2) — if a user's entire pool is down, their requests fail, visibly.

Every path through this method ends by writing exactly one `usage_records` row (`logUsage()`), whether it succeeded, hit an account problem, or failed outright.

## RAG: ingestion

**Upload** — [`Api\DocumentController::store()`](../ai-bridge/app/Http/Controllers/Api/DocumentController.php):
1. Validate the file (`app/Http/Requests/Api/StoreDocumentRequest.php`) — max 20480 KB (~20MB), extension must be one of `DocumentTextExtractor::SUPPORTED_EXTENSIONS` (`txt`, `md`, `pdf`, `docx`; checked via a closure, not Laravel's `mimes` rule, because Symfony's MIME guesser doesn't reliably recognize `.md`).
2. Create a `Document` row (`status: indexing`), flip the parent `KnowledgeBase` to `indexing` too.
3. Store the raw file on the `local` disk under `rag-uploads/{document_id}-{random(8)}.{ext}` — a hard-to-guess path, not the original filename.
4. Dispatch `IngestDocumentJob::dispatch($document->id, $path)`.

**Ingestion job** — [`app/Jobs/IngestDocumentJob.php`](../ai-bridge/app/Jobs/IngestDocumentJob.php) (`ShouldQueue`, `tries = 3`), runs in the dedicated `queue` container — **never inside the web request**:
1. Loads the `Document` bypassing the tenant scope (queue jobs have no request/middleware pipeline) and manually sets `TenantContext` from it before touching anything else.
2. **Extracts plain text** via [`DocumentTextExtractor::extract()`](../ai-bridge/app/Services/Rag/DocumentTextExtractor.php), dispatched on the file's extension:
   - `.txt`/`.md` — read as-is.
   - `.pdf` — parsed with `smalot/pdfparser`.
   - `.docx` — parsed with `phpoffice/phpword`; walks each section's paragraphs and table cells, flattening a paragraph's inline formatting runs (bold/italic spans) into one line instead of splitting mid-sentence.

   Legacy binary `.doc` is **not** supported — only modern `.docx`. Everything downstream of this step only ever sees plain text; chunking doesn't know or care what the original file format was.
3. `TextChunker::chunk($text)` — splits into ~800-token chunks with ~100-token overlap (approximated at 0.75 words/token, no tokenizer dependency), sliding window on word boundaries.
4. For each chunk: embeds it via `OllamaClient` and creates a `Chunk` row (`embedding` = the returned vector).
5. On success, `Document.status = 'ready'`; on any exception (including "no extractable text" — e.g. a scanned/image-only PDF with no text layer), logs it and sets `Document.status = 'failed'`.
6. `finally`: deletes the temp upload file, then recomputes the parent `KnowledgeBase`'s status from all its sibling documents (`indexing` if any document still is, else `ready` if any is ready, else `failed`).

**OllamaClient** — [`app/Services/Rag/OllamaClient.php`](../ai-bridge/app/Services/Rag/OllamaClient.php) — thin HTTP client, `POST {OLLAMA_BASE_URL}/api/embeddings` with `{model: config('services.ollama.embed_model'), prompt}`, 60s timeout, throws on a non-2xx or a missing `embedding` field. The embed model is locked system-wide via config — callers can never override it, which is what enforces "same embedding model at ingest and query" (`mvp-scope.md` risk #4).

## RAG: retrieval

**`RagRetriever::topChunks(KnowledgeBase $kb, string $query, int $limit = 5)`** — [`app/Services/Rag/RagRetriever.php`](../ai-bridge/app/Services/Rag/RagRetriever.php):
1. Embeds the query via the same `OllamaClient`.
2. Builds a raw SQL vector literal (`'[0.1,0.2,...]'::vector`), constructed only from `floatval()`-coerced numbers — never raw user input, to avoid SQL injection through the vector literal.
3. Orders `Chunk::where('knowledge_base_id', $kb->id)` by the pgvector cosine-distance expression (`embedding <=> {literal}`), limits to top-K, returns the chunk text only (nearest first).
4. **Tenant filtering is implicit** — `Chunk` carries `BelongsToTenant`, so the global scope filters by tenant automatically; this method doesn't need to (and doesn't) filter by tenant explicitly. This is still the security-critical guarantee from `AI-BUILD-BRIEF.md` §10.3: every vector search must be tenant-filtered, or one tenant could retrieve another's documents.

Storage: `chunks.embedding` is a Postgres `vector(768)` column (768 = `nomic-embed-text`'s output size, hardcoded — see [05-database-schema.md](05-database-schema.md)), with an HNSW cosine-distance index for approximate nearest-neighbor search, plus a composite `(tenant_id, knowledge_base_id)` index.

## Wiring RAG into the gateway

Step 2 of `ChatCompletionGateway::run()` (above) is the only place RAG connects to the live request path — it's opt-in per app (must have an attached KB) and per KB-readiness (`status === 'ready'`), and it degrades silently: if there's no attached/ready KB, or no chunks come back, the request just proceeds without extra context.
