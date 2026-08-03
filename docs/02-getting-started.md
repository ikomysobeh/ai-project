# Getting Started

## Prerequisites

- Docker Desktop (Windows/Mac/Linux), with Compose v2.
- A free port on your host for each published service (see the table in [08-deployment-docker.md](08-deployment-docker.md)) — by default: `8080` (app), `5173` (Vite HMR), `6969` (WebAI-to-API, localhost-only), `5432` (Postgres), `11434` (Ollama).
- Nothing else — PHP, Node, Postgres, Redis, and Python all run inside containers. You don't need any of them installed locally.

## First-time setup

```bash
git clone <repo>
cd "ai project"

cp .env.example .env
cp ai-bridge/.env.example ai-bridge/.env
cp WebAI-to-API/.env.example WebAI-to-API/.env
cp WebAI-to-API/config.conf.example WebAI-to-API/config.conf

docker compose up --build
```

What happens on first boot:
- `postgres` and `redis` start and wait for healthchecks.
- The `app` container (env `RUN_MIGRATIONS=true`) runs `php artisan migrate --force` and `php artisan storage:link`, then starts `php-fpm`. Only this one container runs migrations, so the `queue`/`vite` containers starting in parallel don't race it.
- `nginx` fronts the `app` container on **http://localhost:8080**.
- `vite` runs the dev/HMR server on **http://localhost:5173** (only used in local dev — see [04-frontend-react.md](04-frontend-react.md)).
- `queue` runs `php artisan queue:work` for background jobs (RAG ingestion).
- `ollama-init` waits for `ollama` to come up, then runs `ollama pull nomic-embed-text` once and exits.
- `webai` (WebAI-to-API) starts on internal port `6969`, headless (`PLAYWRIGHT_HEADLESS=true`).

Once everything is up, open **http://localhost:8080**.

> You do **not** need to generate an `APP_KEY` by hand — the entrypoint script generates one automatically on first boot if `ai-bridge/.env` doesn't already have one (see [08-deployment-docker.md](08-deployment-docker.md#entrypointsh)).

## Golden path: get a working token end to end

This mirrors the project's own Definition of Done (see [`mvp-scope.md`](../mvp-scope.md) §9):

1. **Register** at `http://localhost:8080/register` — this creates a brand-new tenant *and* an owner user in one step (fill in a workspace name, your name, email, password).
2. **Add a Gemini account** — go to **Gemini Accounts** in the console sidebar → *Add Gemini account* → paste `__Secure-1PSID` and `__Secure-1PSIDTS`. See [How to get Gemini cookies](#how-to-get-gemini-cookies-for-an-account) below — **read the fragility warning**, it's the single most common thing that trips people up.
3. **Create an app** — **Apps** → *New App* → name it, pick a default model.
4. **Generate a token** — **API Tokens** → *Generate token* for that app. The raw token is shown **once** — copy it now.
5. *(optional)* **Create a knowledge base** — **Knowledge** → *New knowledge base* → upload a `.md`/`.txt` file → wait for it to reach `ready` → attach it to your app (from the Apps page).
6. **Call the gateway**:
   ```bash
   curl http://localhost:8080/v1/chat/completions \
     -H "Authorization: Bearer <your token>" \
     -H "Content-Type: application/json" \
     -d '{"messages":[{"role":"user","content":"hello"}]}'
   ```
7. **Try the Playground** instead of curl if you'd rather test from the browser — **Playground** in the console sidebar runs the exact same gateway pipeline, authenticated by your session instead of a token.

## How to get Gemini cookies for an account

1. In your browser, sign in to `https://gemini.google.com` with **only the one Google account** you want to add.
2. Open DevTools → **Application** (Chrome) or **Storage** (Firefox) → Cookies → `gemini.google.com`.
3. Copy the values of `__Secure-1PSID` and `__Secure-1PSIDTS` **from that same moment** and paste them into the "Add Gemini account" form immediately.
4. Close that browser session right after.

> ⚠️ **`__Secure-1PSIDTS` is a rotating, short-lived token.** If you leave the tab open, switch Google accounts in the same browser window/profile, or wait too long between copying and pasting, the value you copied may already be stale by the time you submit it — the account will validate as `expired` immediately, even though nothing is actually broken. **Use a fresh private/incognito window per account**, and don't reuse a browser session across multiple accounts. Full explanation: [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md#cookie-fragility-the-1-gotcha).

When you add or re-authenticate an account, the dashboard shows you the **real reason** if validation fails (not just a bare "expired") — hover the status pill, or read the toast message.

## Useful commands

All run against the `app` container (swap for `queue` if you need the worker's shell):

```bash
docker exec tokenforge_app php artisan migrate          # run new migrations
docker exec -it tokenforge_app php artisan tinker        # REPL
docker exec tokenforge_app php artisan queue:work        # run the RAG ingestion worker manually
docker compose logs -f app                                # tail Laravel logs
docker compose logs -f webai                              # tail WebAI-to-API logs
docker compose logs -f queue                               # tail the RAG ingestion worker
```

Frontend, inside the `vite` container (or `app`, both share the bind-mounted `ai-bridge/` directory):
```bash
docker exec tokenforge_vite npx tsc --noEmit -p tsconfig.json   # typecheck
docker exec tokenforge_vite npm run build                        # production build
```

## If something's already gone wrong

Check [10-troubleshooting.md](10-troubleshooting.md) first — it covers the two issues every new dev on this project has hit so far:
- Docker build fails with `invalid file request public/storage`.
- A Gemini account always comes back `expired` no matter what you paste in.
