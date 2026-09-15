# Working on bookslot — quota/token guidance for future sessions

This file exists because Session 20 spent real, avoidable time and tokens
on things a future session doesn't have to repeat. It is not general
advice — every item below is something that actually cost time in a real
session on this specific repo. Update it when a future session hits its
own new instance of this problem, rather than letting the pattern repeat
silently.

## Reading the docs pack — don't read whole files

- **`docs/project-memory/12-session-handoff.md` is 1500+ lines and grows
  every session.** Never read it end to end. Grep for
  `## Amendment (Session` and read only the LAST one — that is current
  ground truth. This repo's own README has been caught stating a session
  number and state that was many sessions stale; the handoff file's own
  latest amendment is the only thing to trust for "what session are we
  actually on and what already exists."
- **`docs/project-memory/09-decision-log.md` is 750+ lines.** Don't read it
  front to back. The header line (line 4) lists every session's D-#### IDs
  in one place — grep for the specific D-#### numbers the handoff's latest
  amendment names, and read only those entries.
- **`docs/project-memory/04-data-model.md` and `05-api-contracts.md` are
  large and change rarely** relative to how often a session needs them.
  Grep for the specific table/endpoint you're touching (e.g. `grep -n
  "^### " 05-api-contracts.md` to get the endpoint list, then read just
  that endpoint's detailed section) instead of reading either file whole.
- **`routes/api.php` is ground truth for what's actually wired**, faster
  and more reliable to check than searching the docs for "is X built yet."
  When the docs and the routes file disagree, the routes file is right.

## Local environment — no Docker daemon in this sandbox

This container has the `docker` CLI but no daemon (`docker ps` fails with
"no such file or directory" on the socket) — `docker-compose.yml` cannot
be used here. Postgres 16 (not 17, but RLS/GIST/`btree_gist` all work
identically), Redis, and RabbitMQ are installable directly:

```bash
apt-get install -y rabbitmq-server   # not preinstalled; postgresql-16 and redis-server already are
service postgresql start
service redis-server start
service rabbitmq-server start
rabbitmq-plugins enable rabbitmq_management   # needed for GET /api/owner/queue-health's broker section
```

**Migrations alone are not enough to make `bookslot_app` (the RLS-subject
runtime role) actually usable — you also need base table grants**, since
Postgres requires ordinary `GRANT`s independent of RLS policies:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO bookslot_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO bookslot_app;
ALTER DEFAULT PRIVILEGES FOR ROLE bookslot_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO bookslot_app;
```

Do this for BOTH the dev database (`bookslot`) and the test database
(`bookslot_test`, per `.env.testing`) — they're separate databases and
each needs its own grants. Skipping this produces a confusing
`SQLSTATE[42501]: permission denied for table X` that looks like an RLS
bug but isn't.

**A fresh container has neither the Postgres roles nor either database —
this isn't just a grants step.** `CREATE ROLE bookslot_migrator ...
BYPASSRLS CREATEDB` and `CREATE ROLE bookslot_app ...` (passwords from
`.env`/`.env.testing`) come first, then `createdb -O bookslot_migrator
bookslot` and `... bookslot_test`, THEN the grants block above, THEN
`php artisan migrate:fresh --seed --database=pgsql_migrator`. Session 20's
note above reads like the roles/DBs already exist; Session 21 found they
don't, on a genuinely fresh container.

**`apt-get install rabbitmq-server` does NOT leave it running** — the
package's own postinst prints `policy-rc.d denied execution of start`
(a container-image default that blocks auto-start-on-install) and exits
0 anyway; run `service rabbitmq-server start` yourself afterward, same as
Postgres/Redis. **The default `guest` user cannot authenticate as this
app's configured RabbitMQ credentials** — `.env`/`.env.testing` set
`RABBITMQ_USER=bookslot`/`RABBITMQ_PASSWORD=bookslot_local_only`, which
don't exist on a fresh broker, producing `ACCESS_REFUSED - Login was
refused using authentication mechanism AMQPLAIN` the first time ANY code
path dispatches a job — not just the `queue-broker` Pest group's own
tests. **This matters even for something as small as one new Feature
test:** `BookingController::store()` (the only way to create an
appointment through the real HTTP API) unconditionally dispatches
`ReleaseExpiredPendingBookingJob` after every successful booking — a real
RabbitMQ broker with a working `bookslot` user must be up before that
endpoint can be called at all, in Feature tests or in a real browser/E2E
run alike, even if the specific behavior under test has nothing to do
with the queue:

```bash
rabbitmqctl add_user bookslot bookslot_local_only
rabbitmqctl set_permissions -p / bookslot ".*" ".*" ".*"
rabbitmqctl set_user_tags bookslot administrator
```

## Composer install in this sandbox

Anonymous `api.github.com` REST calls (used for dist zipball downloads)
are rate-limited/scoped in a way plain `git clone` of the same public repo
is not — `composer install` will fail with "Could not authenticate against
github.com" partway through a large install. Fix: `composer install
--prefer-source` (forces git clone instead of a zipball API call for every
package that has a `source` entry).

**`phpstan/phpstan` has no `source` in its Packagist metadata at all** (it
ships a phar built outside its own git history) — `--prefer-source` can't
help it, and it will still fail the whole install. Two options, in order
of preference:
1. Just skip installing `larastan/larastan`/`phpstan/phpstan` locally
   (temporarily remove `larastan/larastan` from `require-dev`, run
   `composer update`, run your tests, then `git checkout --
   composer.json composer.lock` before committing anything) — the fast
   test gate doesn't need it, and real CI (`.github/workflows/ci.yml`) has
   normal GitHub access and installs it fine.
2. If you actually need to run `composer analyse` locally to verify a
   change before pushing: download the exact phar version your
   `composer.lock` already pins from
   `https://github.com/phpstan/phpstan/releases/download/<version>/phpstan.phar`
   (a different host than the blocked one), combine it with a shallow git
   clone of the same tag (for `bootstrap.php`/the `phpstan` wrapper
   script), and point a temporary `"repositories": [{"type": "path", ...}]`
   entry at that local directory. Revert `composer.json`/`composer.lock`
   to their committed state afterward — never commit this workaround.

## Frontend gotchas found the hard way this session

- **`app/app.vue` must wrap `<NuxtPage />` in `<NuxtLayout>`** for
  `definePageMeta({ layout: '...' })` to have any effect at all. Without
  it, a page using a named layout renders completely blank — no error,
  just nothing — and neither `vue-tsc --noEmit` nor a production build
  catches it. Nuxt's own dev-server console prints a `[NUXT_E4007]`
  warning about this, but only a real browser session (Playwright, or a
  human) ever sees it. If you add a new `layouts/*.vue`, always run at
  least one real Playwright check against a page that uses it before
  trusting it works.
- **`config/cors.php`'s `allowed_headers` cannot be `['*']` once
  `supports_credentials` is `true`** — real browsers (per the Fetch spec)
  treat `'*'` literally rather than as a wildcard once credentials are
  involved, and will silently block the request at preflight. No
  non-browser test in this codebase (including the Sanctum/CSRF Feature
  tests, which send headers directly in-process) can catch this — it only
  showed up in a real Playwright run. Keep `allowed_headers` as an
  explicit list of the headers this app actually sends.
- **Use `http://localhost:PORT`, never `http://127.0.0.1:PORT`, for both
  the frontend and the API in any local/E2E setup.** Cookies are scoped by
  hostname, not by port — `localhost` and `127.0.0.1` are different
  hostnames even though they resolve to the same machine, so mixing them
  breaks the Sanctum SPA cookie pattern (D-0029) silently, in a way that
  looks identical to a real CSRF bug. `playwright.config.ts` and
  `nuxt.config.ts`'s default `apiOrigin` are both already consistent about
  this — don't override one without the other.
- **This environment's pre-installed Chromium build doesn't always match
  what the installed `@playwright/test` version expects** (it looks for a
  `chromium_headless_shell` revision that may not be the one present at
  `/opt/pw-browsers`). Set `PLAYWRIGHT_CHROMIUM_PATH` to the actual
  installed `chrome-linux/chrome` binary and reference it via
  `launchOptions.executablePath` in `playwright.config.ts` rather than
  running `npx playwright install` (which tries to download a new one and
  may not have network access to succeed, or may waste time/bandwidth
  downloading a browser that's already there under a different path).
- **Playwright's `webServer` (`php artisan serve`, `cwd: '..'`) reads
  whatever `.env` is sitting in the repo root at the time — NOT
  `.env.testing`.** `phpunit.xml` sets `APP_ENV=testing`, which is what
  makes Pest/`php artisan test` load `.env.testing` (pointing at
  `bookslot_test`) automatically regardless of the root `.env` file; a
  plain `php artisan serve` has no such override and just reads `.env`
  normally. If `.env` still points at `bookslot_test` (e.g. left over from
  copying `.env.testing` over it to run migrations for the Pest suite),
  every E2E-seeded fixture (the `demo-studio` tenant, its owner login)
  will be silently invisible to the E2E-driven server — `GET
  /api/tenants/demo-studio/services` 404s as `NOT_FOUND`, indistinguishable
  from "seeding never ran" at a glance. Keep `.env` pointed at the plain
  `bookslot` dev database, seeded separately (`php artisan migrate:fresh
  --seed --database=pgsql_migrator` with `.env` set to `bookslot`) from
  whatever `bookslot_test` state the Pest suite has independently built up
  — the two databases, and the two env files, are not interchangeable for
  this purpose even though both ultimately point at "the same kind of
  local Postgres."

## Test suite shape — don't run more than you need

- The fast backend gate (`composer test:fast` / `php artisan test
  --testsuite=Feature,TenantIsolation`) is ~135 tests, ~15 seconds against
  a real local Postgres. Cheap enough to run whole after any backend
  change — no need to hand-pick files once you're done iterating.
- While iterating on ONE new endpoint, run just that file:
  `./vendor/bin/pest tests/Feature/Api/YourNewControllerTest.php` — much
  faster feedback than the whole suite, and this repo's Pest tests are
  fully independent (each spins up its own tenant fixtures), so there's no
  cross-file ordering to worry about.
- **Never reach for SQLite for a quick test** — `07-testing-strategy.md`
  is explicit and this codebase means it literally: `tstzrange`, GIST
  exclusion constraints, and RLS are all Postgres-only, and nothing in
  this suite runs against SQLite anywhere, ever. Don't waste time setting
  one up "just to check something quickly" — it will not reproduce this
  project's actual correctness properties.
- Frontend: `npx vitest run` (whole suite) takes ~2 seconds — no need to
  scope it down. `npx playwright test` (the E2E suite) requires both dev
  servers up first (the config's `webServer` array starts them
  automatically if not already running, but reuses existing ones locally
  — check `reuseExistingServer` in `playwright.config.ts` before assuming
  a fresh run is needed).
