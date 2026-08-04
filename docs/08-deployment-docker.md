# Deployment (Docker Compose)

One root-level [`docker-compose.yml`](../docker-compose.yml) brings up the entire stack — 8 services on one bridge network (`tokenforge`).

## Services

| Service | Image / build | Purpose | Published port |
|---|---|---|---|
| `app` | builds `./ai-bridge` | Laravel PHP-FPM app. `RUN_MIGRATIONS=true` — the only container that runs migrations/`storage:link`. | — (internal `9000`, fronted by `nginx`) |
| `nginx` | `nginx:alpine` | Web server in front of `app`. | `8080:80` |
| `vite` | builds `./ai-bridge` (same image as `app`) | Dev/HMR server for the frontend. Reuses the PHP image because the Wayfinder Vite plugin shells out to `php artisan wayfinder:generate`. `RUN_NPM_INSTALL=true`. | `5173:5173` |
| `queue` | builds `./ai-bridge` | Runs `php artisan queue:work --tries=3 --backoff=5` — the RAG ingestion worker. `RUN_MIGRATIONS=false`. | — |
| `postgres` | `pgvector/pgvector:pg16` | Postgres + the `pgvector` extension. | `5432:5432` (comment out once you don't need host DB access) |
| `redis` | `redis:7-alpine` | Sessions, cache, queue, rate limiting. | — |
| `ollama` | `ollama/ollama:latest` | Runs `nomic-embed-text` for RAG embeddings (CPU). | `11434:11434` (comment out once you don't need host access) |
| `ollama-init` | `ollama/ollama:latest` | One-shot: waits for `ollama`, runs `ollama pull nomic-embed-text`, exits. | — |
| `webai` | builds `./WebAI-to-API` | WebAI-to-API — talks to Gemini via cookies. **Internal only.** | `127.0.0.1:6969:6969` (localhost-only, for local debugging — remove entirely for real deployments) |

`app`, `vite`, and `queue` all bind-mount `./ai-bridge:/var/www/html` (live code editing, no rebuild needed for PHP/JS changes) plus a named volume for `vendor/` (and `node_modules/` for `vite`) so dependencies survive container recreation without needing a host-side install.

## `ai-bridge/Dockerfile`

`php:8.4-fpm-alpine` base. Installs PHP extensions (`pdo_pgsql`, `bcmath`, `gd`, `intl`, `zip`, `pcntl`, `opcache`, `redis` via PECL). Copies `composer.json`/`composer.lock` and runs `composer install` (dev deps included on purpose — this is a dev image, and the bind-mounted `bootstrap/cache/*.php` already reference dev-only providers like Pail/Sail/Pint). Copies `package.json` and runs `npm ci`. Copies the rest of the app, dumps the autoloader, creates `storage/framework/*`/`bootstrap/cache` and chowns them to `www-data`. Sets `docker/entrypoint.sh` as the `ENTRYPOINT`, default `CMD` is `php-fpm`.

### `entrypoint.sh`

Runs on every container start (all three: `app`, `vite`, `queue`):
1. If `vendor/autoload.php` is missing (e.g. a fresh named volume after `composer.json` changed), runs `composer install`.
2. If `.env` is missing, copies it from `.env.example`.
3. If `APP_KEY` isn't set, runs `php artisan key:generate --force` — **you never need to generate this by hand.**
4. If `DB_HOST` is set, polls until Postgres accepts a connection.
5. **Only if `RUN_MIGRATIONS=true`** (the `app` container only): runs `php artisan migrate --force` and `php artisan storage:link`. Restricted to one container so the `queue` worker starting in parallel doesn't race it against the same migrations table.
6. Ensures `storage/framework/*`/`bootstrap/cache` exist and are owned by `www-data`.
7. **Only if `RUN_NPM_INSTALL=true`** (the `vite` container only) and `node_modules/.bin/vite` is missing: runs `npm ci`.
8. `exec`s the container's actual command (`php-fpm`, `npm run dev`, or `php artisan queue:work`).

### nginx (`ai-bridge/docker/nginx/default.conf`)

Standard Laravel PHP-FPM front: serves `public/`, routes everything through `index.php`, proxies `.php` requests to `app:9000` via FastCGI (300s read timeout — RAG/gateway calls can be slow), denies dotfiles except `.well-known`, caps upload size at 25MB.

## `WebAI-to-API/Dockerfile`

Base image `mcr.microsoft.com/playwright/python:v1.60.0-noble` (pre-bundles Playwright + a matching Chromium build). Installs from `requirements.txt` via pip (not poetry, in the image). Sets `PYTHONPATH=/app/src`, exposes port `6969`, runs `python src/run.py --host 0.0.0.0 --port 6969`.

Compose-level env for `webai`: `PLAYWRIGHT_HEADLESS=true` (must stay `true` in Docker — there's no display), plus whatever's in `WebAI-to-API/.env` (mainly Atlas Cloud config, unused by TokenForge).

Volumes:
- `./WebAI-to-API/config.conf:/app/config.conf:ro` — must exist as a **real file** on the host before starting the container, or Docker may create it as a directory and break the app.
- `./WebAI-to-API/runtime:/app/runtime` — read-write; persists `runtime/auth/gemini.json` (the singleton bootstrap login's Playwright storage state) and SQLite conversation snapshots. TokenForge's own per-user account pool doesn't use this — it sends cookies per-request as headers (see [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md)) — but the volume still matters if you ever use WebAI-to-API's own singleton login/dashboard directly.

## Environment files

| File | Consumed by | Contents |
|---|---|---|
| `ai project/.env` (from `.env.example`) | `docker-compose.yml`'s `postgres` service | `POSTGRES_DB/USER/PASSWORD` — **must match** `ai-bridge/.env`'s `DB_*` values. |
| `ai-bridge/.env` (from `.env.example`) | `app`/`vite`/`queue` containers | Full Laravel config — see [03-backend-laravel.md](03-backend-laravel.md#config--environment-variables). |
| `WebAI-to-API/.env` (from `.env.example`) | `webai` container | Only Atlas Cloud vars by default (`ATLASCLOUD_API_KEY`, `ATLASCLOUD_BASE_URL`) — unused unless you enable that provider. |
| `WebAI-to-API/config.conf` (from `config.conf.example`) | `webai` container, mounted read-only | Browser/Gemini backend selection, proxy, Playwright tuning, logging. See [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md#configuration-reference). |

## Networking model

Every service reaches every other one **by service name** on the `tokenforge` bridge network (`http://webai:6969`, `http://ollama:11434`, `postgres:5432`, etc.) — never by `localhost` inside a container. Only `nginx` (`8080`), `vite` (`5173`), `postgres` (`5432`), `ollama` (`11434`), and `webai` (`127.0.0.1:6969`, loopback-only) are published to the host, and the last three are explicitly commented as debug-only conveniences to drop in a real deployment. `webai` is never meant to be reachable from outside the Docker network in production — see [01-architecture.md](01-architecture.md#key-rule-laravel-is-the-only-thing-that-talks-to-webai-to-api).

## Outbound internet access (`app` container)

One exception to "internal network only": [`TokenEstimator`](../ai-bridge/app/Services/Gateway/TokenEstimator.php) (token-usage estimation on every successful gateway call — see [07-gateway-and-rag.md](07-gateway-and-rag.md)) downloads a ~1.7MB BPE vocab file from `openaipublic.blob.core.windows.net` the first time it runs in a given `app` container. This needs real outbound internet access from `app`, not just the internal Docker network — if the host/firewall blocks that, the first gateway call after a container start will fail with a `Yethee\Tiktoken\Exception\IOError` (see [10-troubleshooting.md](10-troubleshooting.md)).

The download is cached under `storage/app/tiktoken-cache/` (bind-mounted, survives a container recreate), so it only happens once per host, not once per container start.
