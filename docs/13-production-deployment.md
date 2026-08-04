# Production Deployment (VPS with host Nginx + Let's Encrypt)

[08-deployment-docker.md](08-deployment-docker.md) covers the Docker Compose stack as it runs today — that's a **local/dev** setup (no TLS, everything on plain HTTP). This doc covers putting that same stack on your real VPS, **alongside the other project that's already running there** on ports 80/443.

> This is a documentation-only deliverable — nothing on your VPS or in this repo has been changed yet. Read it, and tell me when you're ready to actually apply the changes.

## 0. The one thing that matters most: you do NOT need to change ports 80/443

This is the part that wasn't clear before, so let's slow down on it.

Your VPS already has Nginx and Certbot running for your other project, using ports 80 and 443. **That's completely fine — those ports don't belong to that project.** One Nginx, on one VPS, can serve many completely different websites/apps at the same time on the exact same ports 80/443. It picks which app to send a request to by looking at the **domain name** in the request (`server_name` in Nginx-speak), not the port.

Think of it like an apartment building: port 80/443 is the building's front door — shared by everyone. Each app just needs its **own mailbox** (its own domain or subdomain, its own small Nginx config file). Nobody has to move out or change the front door.

So the actual plan is:
1. Your existing project keeps its domain, its Nginx config file, its ports — **untouched, don't touch it at all.**
2. TokenForge gets a **new domain or subdomain** (e.g. `tokenforge.lcportal.cloud`, alongside the `ai.lcportal.cloud`/`backend.ai.lcportal.cloud` your other project already uses).
3. You add **one new, separate** Nginx config file for that new domain — it doesn't conflict with the existing one, because Nginx tells them apart by domain name.
4. The only *internal* Docker ports TokenForge uses (the ones nothing outside the VPS ever sees) need to be different from whatever your other project's Docker setup already uses — already checked, covered in section 1 below.

## 1. What's actually on this VPS (from your diagnostic output)

Real findings, not guesses — this replaces the "go check your ports" step from earlier:

- **Already running**: a separate project (containers named `webai-*`) — a frontend on port `3000`, a bridge API on port `8000`, its own WebAI-to-API instance on `127.0.0.1:6969`, its own Postgres on `127.0.0.1:5433`, plus Redis and NATS (internal-only). Fronted by host Nginx at `ai.lcportal.cloud` / `backend.ai.lcportal.cloud`, with a valid Let's Encrypt cert. **None of this needs to change or be touched.**
- **Two real port conflicts** for TokenForge's own `docker-compose.yml` (not hypothetical — confirmed from your output):
  - Port `6969` is already held by the other project's own WebAI-to-API container.
  - Port `11434` is held by a **native Ollama running directly on the VPS itself** (not inside Docker) — and your firewall explicitly allows the `172.16.0.0/12` range to reach it, which covers Docker's bridge networks. That was clearly set up on purpose, likely so containers can reach it.
- **Confirmed free, no conflict**: port `8080` — which is what TokenForge's own container Nginx already uses by default. No change needed there.
- **Capacity**: 86GB disk free, 6.5GB memory available. Comfortable room to run TokenForge's own stack alongside the existing one.
- **Nginx layout**: config files live at `/etc/nginx/sites-available/{name}` symlinked into `sites-enabled/`, named by short project name (`default`, `webai`) rather than the full domain. We'll follow the same pattern (`tokenforge`).
- **Domain**: the existing project uses subdomains of `lcportal.cloud`. If you control DNS for that domain already, the simplest option is one more subdomain there — e.g. `tokenforge.lcportal.cloud`. (Used as the example domain for the rest of this doc — swap in whatever you actually decide to use.)

### 1.1 `docker-compose.yml` — this project's own ports

| Service | Today (dev, in this repo) | Change to (prod, on this VPS) | Why |
|---|---|---|---|
| `nginx` | `"8080:80"` | `"127.0.0.1:8080:80"` — same port, just loopback-only | Confirmed free; just needs to stop being reachable from anywhere but the VPS itself. |
| `postgres` | `"5432:5432"` | remove the `ports:` entry entirely | Port `5432` happens to be free too, but nothing outside this project's own containers needs to reach Postgres either way — it's already reachable internally as `postgres:5432`. |
| `ollama` | `"11434:11434"` | remove the `ports:` entry entirely — **this one's a real, confirmed conflict** | `11434` is already taken by the native Ollama already running on this VPS. Dropping the mapping (rather than picking a different port) is enough — `app`/`queue` still reach TokenForge's own Ollama container internally as `ollama:11434`, unaffected. *(Optional idea, not required: since a working Ollama already exists on this host and is reachable from containers, you could skip running a second one entirely and point `OLLAMA_BASE_URL` at the existing one instead — saves some memory. Ask me if you want this wired up; it needs `nomic-embed-text` confirmed/pulled there too.)* |
| `webai` | `"127.0.0.1:6969:6969"` | remove the `ports:` entry entirely — **also a real, confirmed conflict** | The other project's own WebAI-to-API container already holds `127.0.0.1:6969`. Dropping the mapping is simplest — nothing outside TokenForge's own containers ever needs to reach it directly, and `app` still talks to it internally as `http://webai:6969` regardless of any host-level port. |
| `vite` | runs `npm run dev` | **don't start this service at all in production** | It's the hot-reload dev server. Production serves pre-built static files instead (see 1.4). |

Since `vite` is just a service you choose not to start (rather than something to delete from the file), always name every service **except** `vite` explicitly when starting the stack:

```bash
docker compose up -d --build app queue nginx postgres redis ollama ollama-init webai
```

A bare `docker compose up -d` (no service names) would also start `vite` — don't use that form on the VPS.

> **Keeping these edits**: make them directly in `docker-compose.yml` after cloning, on the VPS. Since this is your own private deployment, the simplest approach is to just edit that one file in place on the server, and remember to re-apply these edits if you ever `git pull` a version of this file from GitHub again.

### 1.2 One code change: tell Laravel to trust the reverse proxy

Host Nginx will handle HTTPS and forward plain HTTP to the container behind it — but nothing currently tells Laravel that's happening, so it won't realize a request actually arrived over HTTPS. That breaks secure cookies and any `https://` link Laravel generates.

**Fix**: in `ai-bridge/bootstrap/app.php`, inside the `->withMiddleware(function (Middleware $middleware) { ... })` block, add:

```php
$middleware->trustProxies(at: '*');
```

Trusting `'*'` is safe here specifically because the only thing ever forwarding requests to this container is the host Nginx running on the same machine (over loopback) — there's no untrusted network path in between.

*(Say the word and I'll make this edit — it's one line.)*

### 1.3 `ai-bridge/.env` — production values

| Variable | Dev value | Production value | Why |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | Disables debug-friendly behaviors you don't want live. |
| `APP_DEBUG` | `true` | `false` | **Critical** — `true` in production leaks full stack traces (including env values) to anyone who triggers an error. |
| `APP_URL` | `http://localhost:8080` | `https://tokenforge.lcportal.cloud` (or whatever domain/subdomain you actually pick) | Used to generate correct links/redirects and for Sanctum's stateful-domain check. |
| `SESSION_SECURE_COOKIE` | *(not set)* | `true` | Add this line — tells the browser to only ever send the session cookie over HTTPS. |
| `DB_PASSWORD` / root `.env`'s `POSTGRES_PASSWORD` | `tokenforge` | a real, strong, unique password | The dev default is public (it's in this repo's `.env.example`) — never use it in production. |
| `APP_KEY` | auto-generated on first boot | generate fresh on the VPS, then **back it up** | See the callout below — this is the single most important secret in the whole deployment. |

> ⚠️ **`APP_KEY` is what encrypts every stored Gemini cookie and every API token** (see [05-database-schema.md](05-database-schema.md)). If you ever lose it, every Gemini account and every API token in the database becomes permanently undecryptable — not just "hard to recover," genuinely gone. Once it's generated on the VPS, copy it somewhere safe (a password manager, not just the server itself) immediately.

Optional, not required for a first launch:
- `MAIL_MAILER` stays `log` unless you want real password-reset emails to actually send.
- A `REDIS_PASSWORD` — Redis isn't published to the internet either way, so this is defense-in-depth, not a hard requirement. Skip it for a first launch.

### 1.4 Build the frontend for production (no dev server involved)

In dev, `vite` runs a live dev server and the browser loads JS/CSS straight from it. In production there's no such server — Laravel's `@vite` Blade directive automatically falls back to pre-built static files under `ai-bridge/public/build/`, **as long as that directory actually exists with a build in it**:

```bash
docker compose exec app npm ci
docker compose exec app npm run build
```

(`npm ci` first because `node_modules/` is git-ignored — a fresh clone on the VPS won't have it yet.) Do this once on first deploy, and again any time frontend code changes.

## 2. Step-by-step: first deployment

1. **Get a domain or subdomain for TokenForge** — this is separate from your other project's domain. If you control DNS for `lcportal.cloud` (you clearly do, since `ai.lcportal.cloud` and `backend.ai.lcportal.cloud` already exist), the simplest option is one more subdomain there, e.g. `tokenforge.lcportal.cloud` — add one DNS "A" record for it pointing at the *same VPS IP address* your other project already uses (you're not moving to a new server, just adding a new "front door label" on the same one). Give it a few minutes to propagate — `dig tokenforge.lcportal.cloud` should show the right IP before you move on, since Certbot's validation needs this to already be correct.
2. **SSH into the VPS.**
3. **Clone the repo**, in its own separate directory — don't put it inside your other project's folder:
   ```bash
   git clone https://github.com/ikomysobeh/ai-project.git
   cd ai-project
   ```
4. **Create the env files** (git-ignored on purpose — never committed):
   ```bash
   cp .env.example .env
   cp ai-bridge/.env.example ai-bridge/.env
   cp WebAI-to-API/.env.example WebAI-to-API/.env
   cp WebAI-to-API/config.conf.example WebAI-to-API/config.conf
   ```
   Then edit `.env` and `ai-bridge/.env` with the production values from section 1.3 (strong DB password matching in both files, the real `APP_URL` for your new subdomain, `APP_ENV`, `APP_DEBUG`, `SESSION_SECURE_COOKIE`).
5. **Apply the changes from section 1**: the `docker-compose.yml` port edits (1.1) and the `trustProxies` line in `bootstrap/app.php` (1.2).
6. **Build and start everything except `vite`:**
   ```bash
   docker compose up -d --build app queue nginx postgres redis ollama ollama-init webai
   ```
7. **Check it came up cleanly and migrated:**
   ```bash
   docker compose logs -f app
   ```
   (Ctrl+C once it settles — migrations run automatically on this container's start.)
8. **Build the frontend** (section 1.4):
   ```bash
   docker compose exec app npm ci
   docker compose exec app npm run build
   ```
9. **Generate and save the real `APP_KEY`** if `ai-bridge/.env`'s `APP_KEY=` line is still blank:
    ```bash
    docker compose exec app php artisan key:generate --force
    ```
    Then immediately copy the resulting value somewhere safe.
10. **Add a NEW Nginx config file — don't touch your existing project's files (`default`, `webai`).** Following the same short-name pattern already used on this VPS, create `/etc/nginx/sites-available/tokenforge`:
    ```nginx
    server {
        listen 80;
        server_name tokenforge.lcportal.cloud;

        client_max_body_size 25M;

        location / {
            proxy_pass http://127.0.0.1:8080;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
            proxy_http_version 1.1;
        }
    }
    ```
    (Swap `tokenforge.lcportal.cloud` for whatever domain/subdomain you actually settled on in step 1. `127.0.0.1:8080` matches the confirmed-free port from section 1 — no need to change it unless you changed the nginx service's port mapping too.)
    ```bash
    sudo ln -s /etc/nginx/sites-available/tokenforge /etc/nginx/sites-enabled/
    sudo nginx -t
    ```
    `nginx -t` checks the config is valid — it should print "syntax is ok" / "test is successful" and, importantly, should **not** mention `default` or `webai` at all (a sign the three are properly independent). Then:
    ```bash
    sudo systemctl reload nginx
    ```
11. **Get the certificate for just this new domain** — Certbot only ever touches the server block for the domain you name here; your other project's certificate (`ai.lcportal.cloud`) is untouched:
    ```bash
    sudo certbot --nginx -d tokenforge.lcportal.cloud
    ```
    This edits the file from step 10 to add the `listen 443 ssl` half, and sets up auto-renewal alongside the existing `ai.lcportal.cloud` renewal.
12. **Firewall**: `ufw` already allows 80/443/22 for your other project — nothing new to open, since TokenForge shares the same front door. Confirm with `sudo ufw status`.
13. **Test it**: visit `https://tokenforge.lcportal.cloud`, register a workspace, add a Gemini account, create an app/token, and try a real gateway call — the whole golden path from [12-using-the-platform.md](12-using-the-platform.md). Also spot-check your **other** project (`ai.lcportal.cloud`) still works exactly as before.

## 3. Deploying updates after the first time

```bash
git pull
docker compose build app queue
docker compose up -d app queue nginx postgres redis ollama ollama-init webai
```

Then, **only if this pull touched anything under `resources/js` or `resources/css`**:
```bash
docker compose exec app npm run build
```

And **always restart `queue` after any code change** — it's a long-running worker process that doesn't pick up new code on its own (documented in [10-troubleshooting.md](10-troubleshooting.md)):
```bash
docker compose restart queue
```

Migrations run automatically whenever the `app` container starts — no separate migrate step needed.

## 4. What to back up

In rough order of "how bad is it if I lose this":

1. **`ai-bridge/.env`'s `APP_KEY`** — irreplaceable; losing it loses every stored credential.
2. **The `.env` files themselves** (all four) — mostly regeneratable, but painful to reconstruct from memory.
3. **The `postgres_data` Docker volume** — all your tenants, users, apps, tokens, usage history, RAG chunks. Back this up with a scheduled `pg_dump` (or a volume-level snapshot if your VPS provider offers one).
4. `ollama_data` (the embedding model) and `WebAI-to-API/runtime/` — both regenerate automatically, lowest priority.

## 5. Security checklist recap

- [ ] `postgres`, `redis`, `ollama`, and `webai` have no `ports:` published to the host at all (dropped, per section 1.1 — `6969` and `11434` were confirmed already taken by the other project/host); container `nginx` is loopback-only on `8080` (confirmed free).
- [ ] `APP_DEBUG=false` — never leave debug mode on in production.
- [ ] TokenForge's Nginx config is a **separate file** from your other project's — neither was edited to accommodate the other.
- [ ] Strong, unique `DB_PASSWORD`/`POSTGRES_PASSWORD` — not the `tokenforge`/`tokenforge`/`tokenforge` dev default.
- [ ] `APP_KEY` generated fresh on the VPS and backed up somewhere off-server.
- [ ] `bootstrap/app.php` has `trustProxies(at: '*')` and `SESSION_SECURE_COOKIE=true` is set.
- [ ] Your other project was re-tested after all of this and still works normally.
