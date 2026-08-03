# Frontend (Inertia.js + React)

**Important correction to the original planning docs**: this is **not** a separate SPA. It's **Inertia.js + React 19**, server-rendered by the same Laravel app (`ai-bridge/resources/js/`). Confirmed by `@inertiajs/react` in `package.json` and every `Console\*Controller::index()` calling `Inertia::render(...)`. There's no standalone frontend container in production — the `vite` compose service is a dev/HMR server, not how the frontend is served.

## Stack

- **React 19.2**, with React Compiler enabled (`babel-plugin-react-compiler` via the Vite React plugin).
- **Inertia.js v3** (`@inertiajs/react`, `@inertiajs/vite`) for navigation — no react-router. Pages are plain React components under `resources/js/pages/**`, matched by name to `Inertia::render('some/page', [...])` calls on the backend.
- **Routing helpers**: a generated type-safe layer under `resources/js/routes/**` and `resources/js/actions/**`, produced by `@laravel/vite-plugin-wayfinder` — gives typed functions like `login()`, `register()`, `store.form()` that resolve to real Laravel route URLs/methods. This is why `vite` needs the PHP image (`wayfinder:generate` shells out to `php artisan`).
- **Build tool**: Vite 8 (`vite.config.ts`), with `laravel-vite-plugin`, `@inertiajs/vite`, `@vitejs/plugin-react`, `@tailwindcss/vite`, and the Wayfinder plugin. Dev server on port 5173 with polling-based HMR (Docker-friendly).
- **UI library**: shadcn/ui components (`components.json` at repo root) wrapping Radix UI primitives, styled with Tailwind CSS v4 (CSS-first `@theme`, no `tailwind.config.js`). `lucide-react` icons, `sonner` for one of the two toast systems (see below), `input-otp` for 2FA codes.
- **TypeScript**: strict mode, `moduleResolution: bundler`, path alias `@/* → resources/js/*`.
- **Auth extras**: `@laravel/passkeys` (WebAuthn), Laravel Fortify server-side for 2FA/password reset.
- **No client-side data layer** — no React Query, Redux, Zustand, etc. State is local `useState` plus whatever Inertia last loaded into page props; mutations trigger `router.reload({ only: [...] })` to refresh just the changed props.

## Page inventory

All under `resources/js/pages/**`.

**Root**
- `welcome.tsx` — starter-kit splash page at `/`, no shared layout.

**Dashboard**
- `dashboard.tsx` (`GET /dashboard` → `Console\DashboardController`) — 24h stats with day-over-day delta, a 14-day request-volume sparkline, an account-pool "problems" list, top apps by volume.

**Console** (all under `resources/js/pages/console/**`, all wrapped in `ConsoleLayout`, all routes under `/console/*`):
- `apps.tsx` — Apps list; "New App" modal `POST /api/apps`.
- `tokens.tsx` — API Tokens; generate (`POST /api/apps/{id}/tokens`, raw token shown once) and revoke (`DELETE /api/tokens/{id}`).
- `accounts.tsx` — **Gemini Accounts** rotation pool. "Add Gemini account" (`POST /api/accounts` with `{label, secure_1psid, secure_1psidts}`), "Test" (`POST /api/accounts/{id}/test`), "Re-authenticate" for expired accounts (`POST /api/accounts/{id}/reauth`). Shows `last_error` (the real upstream validation failure reason) as a toast and as a tooltip on the status pill. See [06-gemini-accounts-and-webai.md](06-gemini-accounts-and-webai.md).
- `knowledge.tsx` — Knowledge Bases; create, upload (`multipart/form-data` → `POST /api/knowledge-bases/{id}/documents`), view/delete documents.
- `playground.tsx` — interactive gateway test console; `POST /api/playground/{appId}/send`, session-authenticated equivalent of the real `/v1/chat/completions` call. Shows the equivalent `curl` command.
- `admin.tsx` (owner/admin only) — tenant-wide usage/error stats, per-member breakdown. No cost/billing data by design.
- `team.tsx` (owner/admin only) — members list + invite creation (`POST /api/invites` → signed link, 7-day expiry).

**Auth** (`pages/auth/**`, use the starter-kit `AuthLayout`, Fortify-backed, use Inertia's `<Form>` component + Wayfinder route actions):
- `login.tsx`, `register.tsx` (creates tenant + owner, includes a `tenant_name` field), `forgot-password.tsx`, `reset-password.tsx`, `confirm-password.tsx`, `two-factor-challenge.tsx`.
- `invite-accept.tsx` — the "join an existing tenant" flow (distinct from register). `GET /invite/{token}` renders this with invite metadata; the form itself POSTs directly to `/api/invites/{token}/accept` via the plain `api()` fetch helper (not an Inertia form), then hard-redirects to `/dashboard`.

**Settings** (`pages/settings/**`, wrapped in `[AppLayout, SettingsLayout]`):
- `profile.tsx`, `security.tsx` (password + 2FA + passkeys), `appearance.tsx` (light/dark/system).

## Layouts

- **`ConsoleLayout`** (`layouts/console-layout.tsx`) — the console's own shell, completely separate design system from the rest of the app. Sticky sidebar (brand, nav groups: Workspace / Sources / Admin-if-owner-or-admin, tenant footer with logout), sticky topbar (title/crumb/actions slot), content area wrapped in a `ConsoleErrorBoundary`. Imports `resources/css/console.css` directly.
- **`AppLayout`** → `app/app-sidebar-layout.tsx` — the Laravel React starter-kit's default Tailwind/shadcn dashboard shell (sidebar + header). Used for anything not in `console/`, `auth/`, `settings/`, or `welcome`.
- **`AuthLayout`** → `auth/auth-simple-layout.tsx` — used for all `auth/*` pages.
- **`SettingsLayout`** (`settings/layout.tsx`) — left-hand settings nav + content area, combined with `AppLayout`.

## Components

`components/console/**` (used only by console pages, styled by `console.css`):
- **`Pill`** — `{kind: 'active'|'cooling'|'expired'|'ready'|'muted'|'rag', children?, title?}`. `title` renders as a native tooltip — used to surface `last_error` on the accounts page.
- **`StatCard`** — label/value/delta stat tile.
- **`ConsoleModal`** — overlay modal, click-outside-to-close.
- **`Sparkline`** — hand-rolled inline SVG line chart, no charting library.
- **`ConsoleErrorBoundary`** — class component catching render errors, shows the error + stack instead of a blank screen. Wraps the whole app globally and per-page.

`components/ui/**` — standard shadcn-generated primitives (`button`, `dialog`, `select`, `dropdown-menu`, `sonner`, `tooltip`, etc.) — used by auth/settings pages, not the console.

## API client

**`resources/js/lib/console-api.ts`** — the thin `fetch()` wrapper every console page/modal uses to talk to `/api/*` (there is no axios dependency):

- `api<T>(path, options)` — prepends `/api`, sets `credentials: 'same-origin'`, reads the `XSRF-TOKEN` cookie (set by Laravel on every page load) and sends it back as `X-XSRF-TOKEN` so Sanctum's stateful CSRF check passes. `Content-Type: application/json` unless the body is `FormData` (file uploads). On non-OK response, parses the JSON body and throws `ApiError`; on `204`, resolves `null`.
- `ApiError extends Error` — derives `.message` from `body.error.message`, then `body.message`, then a generic fallback — matching the gateway's JSON error envelope shape.

Every console mutation follows the same pattern: `await api(...)` → `router.reload({ only: [...] })` to refresh just the affected Inertia props → `toast(...)`. On failure: `e instanceof ApiError ? e.message : 'Something went wrong.'`.

## Toasts (two separate systems, different purposes)

1. **`ConsoleToastProvider` / `useConsoleToast()`** (`lib/console-toast.tsx`) — a bespoke single-toast-at-a-time context, auto-hides after ~2.2s. Used by every console page's own `fetch()`-based mutations.
2. **`useFlashToast()` + `sonner`** (`hooks/use-flash-toast.ts`) — listens for Inertia's `router.on('flash', ...)` event and shows a `sonner` toast for server-driven flash messages (e.g. a redirect setting `session()->flash('toast', ...)`).

## Styling — two independent stylesheets

- **`resources/css/app.css`** — Tailwind v4 + shadcn CSS variables (OKLCH color tokens, light/dark). Powers `welcome.tsx`, all `auth/*`/`settings/*` pages, and the starter-kit `AppLayout` shell.
- **`resources/css/console.css`** — a large hand-written, non-Tailwind stylesheet scoped entirely under a `.console` class, imported only by `ConsoleLayout`. Ported from an original static HTML prototype and deliberately scoped (rather than global `body`/`a`/`button` selectors) because Inertia keeps one shared `<body>` across every page — a global stylesheet would repaint the Tailwind/shadcn pages too. Defines its own dark design-token palette (`--ink`, `--panel`, `--cyan`, `--coral`, etc.) and plain classnames used directly in JSX as strings: `.card`, `.btn`/`.btn.primary`/`.btn.ghost`, `.pill` + status modifiers, `.tbl`, `.modal-bg`/`.modal`, `.toast`, etc. — no `cn()`/`cva`, no Tailwind utility classes inside `.console`.

Net effect: the console is a deliberately distinct dark "gateway console" design system, while everything else uses Tailwind + shadcn.

## Auth pages — flow summary

- **Login** — email/password/remember, plus an optional passkey widget.
- **Register** — creates a **brand-new tenant + owner** in one step (`tenant_name`, `name`, `email`, `password`). This is account creation, not joining an existing workspace.
- **Invite accept** — the "join an existing tenant" path. An owner/admin generates a signed single-use link from the Team page; visiting it shows tenant/role/email and a self-managed form that posts straight to the JSON API (bypassing Inertia's form handling) and hard-navigates to `/dashboard` on success.
- **Forgot/reset password**, **confirm password**, **two-factor challenge** — standard Fortify-driven flows rendered as Inertia pages.
