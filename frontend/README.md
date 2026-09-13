# bookslot frontend

The Nuxt frontend for `bookslot` (03-architecture.md's D-0002: a decoupled
Laravel API + Nuxt frontend, in this same repository — see that file's
Session 16 amendment for the monorepo-vs-separate-repo reasoning). Calls
the real Laravel API in `../`, never a mock.

The public booking flow lives at `/tenants/{slug}` (services -> slot
picker -> booking form with mandate consent -> deposit payment
confirmation). The owner/admin surface lives under `/owner` (log in with a
studio slug + owner credentials): `/owner/appointments` (list + detail +
cancellation, the default landing page), `/owner/services`,
`/owner/availability` (staff, weekly working hours, one-off exceptions),
`/owner/notifications` (reminder delivery log), and `/owner/queue-health`.
See `docs/project-memory/12-session-handoff.md`'s Session 16/17/20
amendments and `docs/project-memory/09-decision-log.md`'s D-0051 for
exactly what's built vs. still missing (and for the two real
browser-only bugs Session 20's first Playwright run found and fixed —
worth reading before assuming a page "just works" because it typechecks).

## Setup

```bash
npm install
```

The API origin defaults to `http://localhost:8000` (this repository's own
`php artisan serve` port) — copy `.env.example` to `.env` only if the API
runs somewhere else.

## Development

Requires the Laravel API running (`php artisan serve` from the repository
root) against a migrated, seeded database (`php artisan migrate && php
artisan db:seed` — the seeder creates a real bookable `demo-studio` tenant
and an owner login, `owner@demo-studio.test` / `password`, local/testing
only).

```bash
npm run dev
```

Then visit `http://localhost:3000/tenants/demo-studio` for the booking
flow, or `http://localhost:3000/owner` for the owner dashboard.

## Production build / typecheck

```bash
npm run build
npm run typecheck
```

## Tests

```bash
npm run test:unit   # Vitest — composables and components, real Nuxt auto-import context, no mocked network layer
npm run test:e2e    # Playwright — real Chromium against the real Laravel API; starts both dev servers itself
```

`test:e2e` needs a real Postgres/Redis/RabbitMQ stack migrated and seeded
(`php artisan migrate:fresh --seed` from the repository root) before it
runs — it exercises the real `demo-studio` seed data, not fixtures of its
own. Use `http://localhost:*`, never `http://127.0.0.1:*`, for anything
touching this app's cookies (see `playwright.config.ts`'s own comment) —
Sanctum's SPA cookie pattern depends on the frontend and API sharing a
hostname, and `localhost`/`127.0.0.1` are different hostnames even though
they're the same machine.
