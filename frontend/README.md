# bookslot frontend

The Nuxt frontend for `bookslot` (03-architecture.md's D-0002: a decoupled
Laravel API + Nuxt frontend, in this same repository — see that file's
Session 16 amendment for the monorepo-vs-separate-repo reasoning). Calls
the real Laravel API in `../`, never a mock.

Currently one real page: the public booking flow at `/tenants/{slug}`
(services -> slot picker -> booking form with mandate consent -> deposit
payment confirmation). See `docs/project-memory/12-session-handoff.md`'s
Session 16 amendment for exactly what's built vs. still missing.

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
artisan db:seed` — the seeder creates a real bookable `demo-studio` tenant,
since there's no owner dashboard yet to create one through).

```bash
npm run dev
```

Then visit `http://localhost:3000/tenants/demo-studio`.

## Production build / typecheck

```bash
npm run build
npx nuxi typecheck
```
