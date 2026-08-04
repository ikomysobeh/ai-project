# TokenForge Developer Documentation

Start here if you're new to this project. Read in order, or jump straight to what you need.

| # | Doc | What's in it |
|---|---|---|
| 1 | [Architecture](01-architecture.md) | The big picture: what this product is, the three moving parts, repo layout, request flows, multi-tenancy, and where the implementation has drifted from the original plan docs. **Read this first.** |
| 2 | [Getting Started](02-getting-started.md) | `docker compose up` to a working token, end to end. How to actually get usable Gemini cookies. |
| 3 | [Backend (Laravel)](03-backend-laravel.md) | Tenancy, auth, every model, every controller, routes, config, rate limiting, jobs. |
| 4 | [Frontend (Inertia + React)](04-frontend-react.md) | The stack, every page, layouts, components, the API client, styling, state. |
| 5 | [Database Schema](05-database-schema.md) | Every table, every column, the pgvector setup. |
| 6 | [Gemini Accounts & WebAI-to-API](06-gemini-accounts-and-webai.md) | The most-important-to-understand integration in the whole system: how the per-user account pool talks to Gemini, why cookies expire, and the exact patch that makes multi-account rotation possible. |
| 7 | [Gateway & RAG](07-gateway-and-rag.md) | The full `/v1/chat/completions` pipeline step by step, plus document ingestion and retrieval. |
| 8 | [Deployment (Docker)](08-deployment-docker.md) | Every compose service, both Dockerfiles, the entrypoint script, env files, networking. |
| 9 | [API Reference](09-api-reference.md) | Every route in the app, grouped by area, plus the internal WebAI-to-API surface. |
| 10 | [Troubleshooting](10-troubleshooting.md) | The issues every new dev on this project has actually hit, and how to fix them. |
| 11 | [Roadmap & Future Ideas](11-roadmap-and-ideas.md) | What could come after v1 — a backlog of feature ideas, grouped by area and rough size. Not committed scope. |
| 12 | [Using the Platform](12-using-the-platform.md) | The user-facing guide: creating apps and tokens, adding Gemini accounts, RAG, and the full `/v1/chat/completions` parameter reference. Not a codebase doc — read this one if you just want to *use* TokenForge. |
| 13 | [Production Deployment](13-production-deployment.md) | Taking the Docker Compose stack from local dev to a real VPS fronted by a host Nginx + Certbot — what has to change, and the exact step-by-step first deploy. |

## Also worth knowing about

- [Token Usage Tracking — شرح مبسّط بالعربي](token-usage-tracking-ar.md) — a short, non-technical explainer (in Arabic) of the token-usage-estimation feature: what it is, why Gemini's web interface can't give a real count, and how the estimate works instead.
- [`mvp-scope.md`](../mvp-scope.md) and [`AI-BUILD-BRIEF.md`](../AI-BUILD-BRIEF.md) at the repo root — the **original** scope and architecture planning documents. Still useful for the "why" behind a lot of decisions, but written before the build started — where they disagree with what's actually in the code, this doc set (based on reading the real implementation) wins. Divergences are called out explicitly in [01-architecture.md](01-architecture.md#divergences-from-the-original-plan).
- [`WebAI-to-API/docs/`](../WebAI-to-API/docs/) and [`WebAI-to-API/README.md`](../WebAI-to-API/README.md) — the vendored service's own documentation, covering things this doc set doesn't (its full Gemini protocol internals, its own dashboard, its Playwright engine details). [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md) covers only the slice of it relevant to how TokenForge integrates.
