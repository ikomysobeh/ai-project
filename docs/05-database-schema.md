# Database Schema

PostgreSQL 16 with the `pgvector` extension (image: `pgvector/pgvector:pg16`). Migrations live in `ai-bridge/database/migrations/`.

Every table below except `tenants` and the Laravel-stock ones (`users`, `cache`, `jobs`, `passkeys`, `personal_access_tokens`) carries a `tenant_id` foreign key, cascade-deletes with its tenant, and is filtered by the `TenantScope` global scope (see [03-backend-laravel.md](03-backend-laravel.md#tenancy)).

## Core tenancy

**`tenants`**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | |
| slug | string, unique | |
| status | string | default `active` |

**`users`** (extended from Laravel's stock table)
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants, nullable | cascade delete |
| role | string | default `member`; `owner`\|`admin`\|`member` |
| status | string | default `active`; `active`\|`invited`\|`suspended` |
| *(+ standard Laravel columns: name, email, password, two-factor columns, passkeys)* | | |

**`invites`**
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| email | string, nullable | |
| role | string | default `member` |
| signed_token | string, unique | `Str::random(48)` |
| expires_at | timestamp | |
| used_at | timestamp, nullable | |

## Apps, tokens, usage

**`apps`** (model: `Application`)
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| user_id | FK → users | owner of the app |
| name | string | |
| default_model | string | |
| knowledge_base_id | FK → knowledge_bases, nullable | `nullOnDelete` |
| status | string | default `active` |

**`api_tokens`**
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| app_id | FK → apps | |
| name | string | |
| token_hash | string, unique | sha256 of the raw token |
| prefix | string | first 10 chars of the raw token, for display |
| rate_limit | unsigned int | default 60 (requests/minute) |
| daily_quota | unsigned int | default 1000 |
| last_used_at, revoked_at, expires_at | timestamp, nullable | |

**`usage_records`**
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| app_id | FK → apps | |
| token_id | FK → api_tokens, nullable | `nullOnDelete` |
| upstream_account_id | FK → upstream_accounts, nullable | `nullOnDelete` |
| model | string | |
| prompt_tokens, completion_tokens, total_tokens | unsigned int | default 0 |
| latency_ms | unsigned int, nullable | |
| status | string | `success`\|`error` |
| error_type | string, nullable | e.g. `no_active_accounts`, `upstream_unreachable`, `upstream_error`, `all_accounts_unavailable` |
| used_rag | boolean | default false |
| created_at | timestamp, nullable | **no `updated_at`** (`UPDATED_AT = null` on the model) |

## Gemini account pool

**`upstream_accounts`**
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| user_id | FK → users | **per-user pool — no shared/tenant-wide fallback by design** |
| label | string | user-chosen display name |
| cookies_encrypted | text | `{psid, psidts}`, Laravel `encrypted:array` cast (AES, app-key based); hidden from serialization |
| status | string | default `active`; `active`\|`cooling_down`\|`expired` |
| last_used_at | timestamp, nullable | drives LRU account selection |
| cooldown_until | timestamp, nullable | set on a 429 (5 minutes out) |
| error_count | unsigned int | default 0; reset on `markHealthy()` |
| health_checked_at | timestamp, nullable | |
| last_error | text, nullable | *(added 2026-08-03)* the real upstream validation failure reason, e.g. a rotated `__Secure-1PSIDTS` — see [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md) |

Max 5 accounts per user, enforced in `Api\UpstreamAccountController::store()`, not the schema.

## RAG

**`knowledge_bases`**
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| user_id | FK → users | |
| name | string | |
| embedding_model | string | default `nomic-embed-text`; **locked per KB** — ingestion and query must use the same model |
| status | string | default `empty`; `empty`\|`indexing`\|`ready`\|`failed` |

**`documents`**
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| knowledge_base_id | FK → knowledge_bases | |
| source_name | string | original filename |
| source_type | string | `txt`\|`md` — v1 supports plain text only |
| status | string | default `indexing`; `indexing`\|`ready`\|`failed` |

**`chunks`**
| Column | Type | Notes |
|---|---|---|
| tenant_id | FK → tenants | |
| document_id | FK → documents | |
| knowledge_base_id | FK → knowledge_bases | |
| content | text | chunk text (~800 tokens, ~100 overlap) |
| token_count | unsigned int | default 0 |
| metadata | json, nullable | |
| embedding | `vector(768)` | pgvector column; 768 = `nomic-embed-text`'s output size, **hardcoded** since the MVP locks one embedding model system-wide |

Indexes on `chunks`:
- `chunks_tenant_kb_idx` — composite `(tenant_id, knowledge_base_id)`. Tenant-filtered vector search is a **security requirement**, not just performance — every retrieval query must filter by tenant, or one tenant could retrieve another's documents.
- `chunks_embedding_hnsw_idx` — `USING hnsw (embedding vector_cosine_ops)` for approximate nearest-neighbor search.

The `vector` extension is created with `CREATE EXTENSION IF NOT EXISTS vector` in the `chunks` migration itself.

## Entity-relationship summary

```
tenants ──< users ──< apps ──< api_tokens
   │           │         │
   │           │         └──< usage_records >── upstream_accounts ──< (belongs to a user)
   │           │
   │           ├──< upstream_accounts (per-user Gemini pool)
   │           └──< knowledge_bases ──< documents ──< chunks
   │
   └──< invites
```

All `──<` edges cascade-delete from the tenant/parent side except where noted (`nullOnDelete` on `apps.knowledge_base_id`, `api_tokens`/`upstream_accounts` FKs on `usage_records`).
