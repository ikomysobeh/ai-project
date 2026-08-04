# Troubleshooting

Known issues every new dev on this project is likely to hit, in rough order of likelihood.

## "I added/changed code the `queue` container uses, but it's still behaving like the old version"

`php artisan queue:work` (the `queue` container's whole job — see [08-deployment-docker.md](08-deployment-docker.md)) boots the framework **once** when the process starts and keeps that same PHP process alive for every job after that, unlike `app`'s PHP-FPM workers (which effectively pick up file changes per-request, since `opcache.validate_timestamps=1` is set — see `ai-bridge/docker/php/local.ini`). If the `queue` container has been running since before you added a new class, ran `composer require`, or changed anything `IngestDocumentJob` (or anything it calls) depends on, it's still executing the code as it was when it booted — new classes, new Composer packages, and edited files are all invisible to it until it restarts.

Symptom: a background job (right now, only RAG document ingestion) silently fails or behaves like an old version of the code, even though `app` clearly has the new code (e.g. upload validation already accepts a new file type, but ingestion still fails).

Fix: `docker compose restart queue` after any change that touches the ingestion path — new Composer packages, new/changed classes under `app/Jobs`, `app/Services/Rag`, etc. There's no auto-reload for this container; it's a plain long-running worker process like any other Laravel queue deployment.

## "Gateway calls fail with `Yethee\Tiktoken\Exception\IOError` / `Checksum failed`"

Token-usage estimation ([`TokenEstimator`](../ai-bridge/app/Services/Gateway/TokenEstimator.php)) needs to download a BPE vocab file from `openaipublic.blob.core.windows.net` the first time it runs in a given `app` container — see [08-deployment-docker.md](08-deployment-docker.md#outbound-internet-access-app-container). Two distinct causes land on the same error:

- **No outbound internet access from `app`.** If the host/firewall blocks it, every gateway call fails until that's fixed — there's no offline fallback.
- **The cache directory (`storage/app/tiktoken-cache/`) was created by the wrong user.** If you ever exercise this code via `docker exec` (which runs as `root` by default) before a real request does (which runs as `www-data`, the php-fpm worker user), the directory ends up `root`-owned at `0750` — unreadable/unwritable by `www-data`, so real requests then fail even though the vocab file is already sitting right there. Fix: `docker exec tokenforge_app chown -R www-data:www-data storage/app/tiktoken-cache`. Avoid re-triggering it: test this path with `docker exec -u www-data ...` instead of plain `docker exec`.

## "Docker build fails: `invalid file request public/storage`"

```
=> ERROR [queue internal] load build context
=> => transferring context: ...
------
> [queue internal] load build context:
------
target app: failed to solve: invalid file request public/storage
```

**Cause**: `ai-bridge/public/storage` is a symlink Laravel creates at container startup (`php artisan storage:link`, run by [`entrypoint.sh`](08-deployment-docker.md#entrypointsh) on the `app` container). Because `ai-bridge/` is bind-mounted into the containers, that symlink also lands on the **host** filesystem, pointing at the literal in-container path `/var/www/html/storage/app/public` — meaningless outside the container. On Windows especially, Docker's build-context tarring chokes on a symlink like that, and since `queue` and `app` share the same `./ai-bridge` build context, the build fails.

**Fix** (already applied — see `ai-bridge/.dockerignore`): `public/storage` is excluded from the build context, same as `storage/framework/*`/`bootstrap/cache/*` right above it — it's a runtime-generated artifact, never something the image should bake in.

**If you hit this again** (e.g. a fresh clone before the entrypoint has run once): add `public/storage` to `ai-bridge/.dockerignore` if it's missing, and remove the stray symlink from the host (`rm ai-bridge/public/storage` on Unix, or delete it via Explorer/`Remove-Item` on Windows — it's just a symlink, not data). The `app` container recreates it automatically on next start.

## "I added/re-authenticated a Gemini account and it says `expired`"

**This is very likely not a bug** — it's `__Secure-1PSIDTS` cookie fragility, the #1 known operational gotcha of this whole system. Full explanation and how to avoid it: [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md#cookie-fragility-the-1-gotcha).

Quick diagnosis: hover the "expired" status pill (or read the toast) — it now shows the **real** upstream failure reason (fixed 2026-08-03; previously every failure just said "expired" with no explanation). If it mentions `SECURE_1PSIDTS`, it's cookie rotation — use a fresh private/incognito window per account and paste immediately after login. If it's something else (a timeout, a connection error, a non-401 status), that's a real problem — check `docker compose logs webai`.

## "The gateway returns 503 `No active Gemini accounts`"

Expected behavior when a user's entire personal account pool is `expired`/`cooling_down` — there is **no shared/tenant-wide fallback pool** by design (`mvp-scope.md` §2: each user's tokens can only be served by that user's own Gemini accounts). Add or re-authenticate at least one account for that specific user.

## "WebAI-to-API container won't start / `config.conf` looks empty or is a directory"

`docker-compose.yml` bind-mounts `./WebAI-to-API/config.conf:/app/config.conf:ro`. If that file doesn't exist on the host when Compose first starts the container, Docker can silently create it as an **empty directory** instead of failing, which then breaks the app in confusing ways. Make sure you've run `cp WebAI-to-API/config.conf.example WebAI-to-API/config.conf` (see [02-getting-started.md](02-getting-started.md)) — and if you suspect this already happened, check `ls -la WebAI-to-API/config.conf`; if it's a directory, delete it, recreate the file from the example, and restart the `webai` service.

## "My controller's route-model-bound `Application`/`ApiToken` 404s even though the row exists"

By design — see [03-backend-laravel.md](03-backend-laravel.md#tenancy). Implicit Eloquent route-model binding runs *before* `SetTenantContext` sets the tenant, so binding a tenant-scoped model that way would always 404 against the fail-closed `TenantScope`. Controllers in this codebase deliberately type-hint `int $app`/`int $token` and look the row up manually inside the method body, after middleware has run. Follow that pattern for new controllers rather than switching back to implicit binding.

## "I ran migrations twice / the `queue` container raced the `app` container"

Shouldn't happen — only the `app` container has `RUN_MIGRATIONS=true`; `vite` and `queue` both explicitly set it to `false`. If you've customized `docker-compose.yml` or added a new container that also needs the database, make sure exactly one of them owns `RUN_MIGRATIONS=true`.

## "A background job / health check silently isn't running"

There isn't one, for accounts — this is a known gap versus the original plan, not a bug you introduced. See [01-architecture.md](01-architecture.md#divergences-from-the-original-plan) and [03-backend-laravel.md](03-backend-laravel.md#jobs). The only queued job in the app is `IngestDocumentJob` (RAG ingestion). If you need periodic account health checks, you'll need to add a scheduled command — there's no existing scaffolding for one yet.

## Miscellaneous notes worth knowing (not bugs)

- **WebAI-to-API's license is Apache-2.0**, not AGPLv3 as the original planning docs assumed and flagged as needing manager sign-off — that blocker no longer applies to the vendored version in this repo.
- **`GET /ready` on WebAI-to-API does not check Gemini auth validity** — don't wire it into any health dashboard as an account-health signal.
- **WebAI-to-API's auth coordination lock is process-bound (`in_memory` only)** — fine for the single-container setup this project runs, but if you ever scale `webai` to multiple replicas/workers, its own login coordination (not TokenForge's per-request cookie override, which doesn't use this lock at all) is not distributed-safe.
