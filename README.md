# TokenForge

A multi-tenant SaaS gateway: users create "apps," generate API tokens, and call an **OpenAI-compatible** `POST /v1/chat/completions` endpoint. Behind the scenes, requests are forwarded to **Google Gemini** via cookie-based auth (no API key), rotating through each user's own pool of Gemini accounts to survive cookie expiry, optionally enriching prompts with the user's own documents (RAG), and logging everything for an admin dashboard.

## Stack

- **`ai-bridge/`** — Laravel 12 + Inertia.js + React 19. Both the JSON API and the server-rendered frontend, in one app.
- **`WebAI-to-API/`** — a cloned FastAPI service that talks to Gemini using browser session cookies.
- **PostgreSQL** (with `pgvector`), **Redis**, **Ollama** (`nomic-embed-text` for RAG embeddings).
- Everything orchestrated by one root `docker-compose.yml`.

## Quick start

```bash
cp .env.example .env
cp ai-bridge/.env.example ai-bridge/.env
cp WebAI-to-API/.env.example WebAI-to-API/.env
cp WebAI-to-API/config.conf.example WebAI-to-API/config.conf

docker compose up --build
```

Then open **http://localhost:8080** and register — this creates your workspace (tenant) and owner account. From there: add a Gemini account (paste browser cookies), create an app, generate a token, and call the gateway.

## Documentation

**Start with [`docs/README.md`](docs/README.md)** — a full developer-onboarding doc set covering architecture, setup, the backend, the frontend, the database, the Gemini account/WebAI-to-API integration (read this one before touching accounts or the gateway), the request pipeline, deployment, the full API reference, known gotchas, and a [roadmap of ideas for what's next](docs/11-roadmap-and-ideas.md).

The original scope/planning docs are still at the repo root for background: [`mvp-scope.md`](mvp-scope.md), [`AI-BUILD-BRIEF.md`](AI-BUILD-BRIEF.md). Where they disagree with `docs/`, `docs/` reflects what's actually built.
