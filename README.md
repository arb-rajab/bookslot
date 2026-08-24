# bookslot

**Private commercial project — booking, deposits, and no-show protection for
appointment-based service businesses.**

> `bookslot` is a working name, not a final commitment — see
> `docs/project-memory/00-project-brief.md`.

This repository is at **Session 8 (first implementation session)**. The
Laravel API scaffold, database schema, and tenant-isolation test suite exist;
no controllers, routes, Stripe integration, or frontend do yet. See
`docs/project-memory/12-session-handoff.md` for the current state and what
the next session should build.

## Start here

- [`docs/project-memory/00-project-brief.md`](docs/project-memory/00-project-brief.md) —
  what this is, why it exists, and the business assumptions behind it.
- [`docs/project-memory/01-scope-and-non-goals.md`](docs/project-memory/01-scope-and-non-goals.md) —
  MVP scope, paid-expansion roadmap, and explicit non-goals.
- [`docs/project-memory/03-architecture.md`](docs/project-memory/03-architecture.md) —
  the recommended technical architecture and why.
- [`docs/project-memory/04-data-model.md`](docs/project-memory/04-data-model.md) —
  the authoritative schema this session's migrations implement.
- [`docs/project-memory/12-session-handoff.md`](docs/project-memory/12-session-handoff.md) —
  current state and what the next session should do.

## Local development

Requires PHP 8.4+, Composer, and Docker.

```bash
docker compose up -d          # Postgres 17 + Redis
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --database=pgsql_migrator --force
```

Two Postgres roles exist from the first migration onward, per D-0009/D-0020
(`docs/project-memory/09-decision-log.md`): `bookslot_app` (the only role the
running application ever authenticates as — ordinary, fully RLS-subject) and
`bookslot_migrator` (owns the tables, holds `BYPASSRLS`, used only for
migrations/seeders/backfills — never by the running app). Both are created
automatically by `docker/postgres/init/01-roles-and-database.sql` the first
time the `postgres` container's data volume initializes. This split is why
migrations are always run with `--database=pgsql_migrator` explicitly, never
against the application's own default connection.

```bash
composer ci:check     # lint + static analysis + the fast test suite
composer test:fast    # just the fast test suite (excludes @group slow)
composer test:tenant-isolation  # just the tenant-isolation suite
```

Tests run against a separate `bookslot_test` database (also created by the
same init script), never the local dev `bookslot` database — see
`docs/project-memory/07-testing-strategy.md`. There is no SQLite fallback
anywhere in this suite: the schema depends on `tstzrange`, GIST exclusion
constraints, and Row-Level Security, none of which SQLite supports.

## Status

- **License:** Proprietary — All Rights Reserved. See [`LICENSE`](LICENSE).
  This is closed-source private software, not open source.
- **Track:** Private (this repository has no public counterpart and is not
  part of the public portfolio's framework-allocation-ledger process).
- **Code:** Laravel API scaffold, database schema/migrations, tenant-context
  plumbing, and the tenant-isolation test suite. No controllers, routes,
  Stripe integration, background jobs beyond the tenant-context mechanism
  itself, or frontend yet.
