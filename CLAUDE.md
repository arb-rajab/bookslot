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
don't, on a genuinely fresh container. Session 22: confirmed the container
is fresh again every session — don't assume Session 21's setup persisted.

**Run `createdb`/`psql` as the `postgres` OS user directly
(`sudo -u postgres createdb -O bookslot_migrator bookslot`), never with
`-h 127.0.0.1 -U postgres`.** This sandbox's Postgres has no trust/password
configured for TCP host connections as the `postgres` superuser — a `-h
127.0.0.1 -U postgres` invocation hangs silently retrying a `Password:`
prompt forever (no TTY to answer it) rather than failing fast, and will
eat your command's timeout doing it. `sudo -u postgres <command>` uses the
local peer-auth socket instead and returns immediately. This cost Session
22 a stuck background command that had to be killed.

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

- **`composer test:fast` (`pest --exclude-group=slow,queue-broker`) does
  NOT actually exclude `queue-broker` right now — verified directly
  (Session 22).** No test in this repo currently carries a `slow` tag
  (`./vendor/bin/pest --group=slow` finds zero tests) — combining that
  nonexistent group name with the real `queue-broker` one in a single
  `--exclude-group=a,b` silently makes the *entire* exclusion a no-op in
  this Pest/PHPUnit version, and all 3 `queue-broker` RabbitMQ-integration
  tests run anyway. This means `composer test:fast`/`composer ci:check`
  require a real local RabbitMQ broker to pass at all right now, contrary
  to what this file and `07-testing-strategy.md` otherwise say about that
  group being excluded from the fast gate. Two ways to actually get the
  fast, broker-free gate this script is supposed to give you: run
  `./vendor/bin/pest --exclude-group=queue-broker` directly (drop the
  nonexistent `slow`), or just stand up RabbitMQ anyway (this file's own
  section below) since Session 19 already made it necessary for other
  reasons. The real fix — adding a `slow` tag to at least one real test, or
  changing `composer.json`'s `test:fast` script to stop naming a group that
  doesn't exist — was not made this session (out of D-0048's own scope);
  flagged here so it isn't rediscovered by hand-tracing a config file
  again.
- The fast backend gate (`composer test:fast` / `php artisan test
  --testsuite=Feature,TenantIsolation`) is ~135-146 tests, ~10-16 seconds
  against a real local Postgres (with the broker caveat immediately
  above). Cheap enough to run whole after any backend change — no need to
  hand-pick files once you're done iterating.
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

## Session 23 additions — Playwright E2E gotchas for owner-admin flows specifically

- **A fresh container may have no `.env` file at all, not just a stale
  one.** Session 21/22 documented what to do once `.env` exists and is
  pointed at the wrong database; Session 23's container had no `.env` at
  all (`ls .env` → "No such file or directory"). `cp .env.example .env`
  gives you the right defaults already (`DB_DATABASE=bookslot`, correct
  role names) — just remember to run `php artisan key:generate --force`
  immediately after, and again after any subsequent `cp .env.example .env`
  (copying over `.env` wipes `APP_KEY` back to empty every time).
- **Playwright spec/support files run as real ES modules — `__dirname` is
  not defined and throws `ReferenceError` at import time**, not just when
  used. If a spec/support file needs its own directory (e.g. to shell out
  to a sibling script with a repo-relative path), use
  `path.dirname(fileURLToPath(import.meta.url))` instead. This fails at
  collection time, before any test runs, so it can look like every test in
  the file mysteriously vanished rather than a stack trace pointing at the
  real line.
- **Triggering a real *delayed* background job (e.g. `ReleaseExpiredPendingBookingJob`,
  FR-05's hold-window expiry) from a Playwright E2E test without actually
  waiting out its real delay (15 real minutes by default) or standing up
  RabbitMQ's delayed-message plumbing inside the test itself:** shell out
  to `php artisan tinker <script.php>` (via Node's `execFileSync`, `cwd`
  set to the repo root) running a small PHP script that backdates the
  target row's `created_at` and calls the job's own `dispatchSync()` —
  the exact same technique the equivalent Pest Feature test already uses,
  replayed against the real dev database the E2E-driven `php artisan
  serve` process is actually reading. Pass identifiers via an environment
  variable (`E2E_APPOINTMENT_ID=... php artisan tinker ...`), not `argv` —
  `artisan tinker <file>` does not forward extra CLI arguments into the
  executed script. See `frontend/tests/e2e/support/expire-hold-window.php`
  and `support/booking.ts`'s `expireHoldWindow()` for the working example.
  This is a test-harness technique only — it must never become a reachable
  HTTP route (would blur J4's no-automatic-no-show-detection boundary) and
  must never be treated as a substitute for `RabbitMqQueueIntegrationTest`
  (`queue-broker` group), which is what actually proves the real delayed
  dispatch works.
- **A frontend page that assigns an API response's shape directly onto its
  own state (e.g. `detail.value = await apiFetch(...)`) is only as safe as
  every endpoint that can produce that response returning the SAME shape.**
  `Owner\AppointmentController::cancel()` and `show()` feed the exact same
  `frontend/app/pages/owner/appointments/[id].vue` page, but only `show()`
  originally built the full `payments`/`reminders`/`events`-bearing detail
  object — `cancel()` returned the slim list-row shape `index()`/
  `updateStatus()` correctly use elsewhere, and the page's own template
  unconditionally reads `detail.payments.length`, crashing on `undefined`
  the instant a real owner clicked "Cancel appointment." No Feature test
  caught this (it only asserted the JSON body's own fields, never how a
  Vue template already showing the fuller shape would react to a thinner
  one replacing it) — only a real Playwright click did. When one backend
  endpoint's response feeds a frontend state slot another, richer endpoint
  also feeds, grep for every consumer of that state slot before trusting a
  narrower response shape is safe to return from a new or changed action.

## Session 24 additions — Dependabot PR review findings

- **`vue-tsc@^3.3.11` (pinned in `frontend/package.json`) is incompatible
  with TypeScript 7.x right now.** TS 7.0.2 removed the `./lib/tsc`
  subpath from its package `exports` map; the installed `vue-tsc` still
  resolves the type-checker through that removed subpath and crashes
  immediately with `ERR_PACKAGE_PATH_NOT_EXPORTED` before it typechecks a
  single file. This is not theoretical — it's the actual, confirmed cause
  of the real CI failure on the `dependabot/npm_and_yarn/frontend/
  typescript-7.0.2` PR (checked by pulling the job log directly, not just
  trusting the red X). Don't merge a TypeScript major bump here until
  `vue-tsc` ships a release that supports TS 7's new export map — the two
  need to be bumped together and re-verified together, not one at a time.
  Left that PR open with this reasoning posted on it.
- **GitHub Actions bumps (`actions/checkout`, `actions/cache`,
  `actions/setup-node`, `actions/upload-artifact`) — v4→v6/v7 — merged
  clean with zero workflow changes needed.** This repo's `.github/
  workflows/*.yml` only uses these actions with basic, stable inputs
  (checkout with no extra options, `cache`'s `path`/`key`, `setup-node`'s
  `node-version`, `upload-artifact`'s default usage) — none of the
  interface changes across those major version bumps touch what this repo
  actually passes in. CI was already green on all four PRs before this
  session even looked at them; merging didn't require re-running anything
  beyond confirming that.
- **`vue-router` 5.2.0 → 5.3.1 needed real scrutiny per the task brief
  (it's the one actual runtime frontend dependency here), but the
  scrutiny turned up very low actual exposure**: this codebase is Nuxt
  4, and app code never imports `vue-router` directly — no
  `router.beforeEach`, no direct `useRouter`/`useRoute` from
  `'vue-router'`. Routing goes entirely through Nuxt's own wrappers
  (`useRoute()`, `navigateTo()`, `definePageMeta()`), which insulate app
  code from vue-router's own API surface. Combined with it being a minor
  bump within the same major version, CI already green (typecheck +
  Vitest + full Playwright E2E), and a from-scratch local `npm ci` +
  `vitest run` (10/10 passed) + `vue-tsc --noEmit` (clean) repeated
  against that exact branch to confirm the CI result independently — this
  merged clean with no code changes needed. If a future vue-router bump
  ever needs a *major*-version jump, this "Nuxt insulates us" reasoning
  should be re-checked, not assumed to still hold — Nuxt's own internal
  vue-router usage could still be affected even if app code isn't.
- **`@types/node` bumps can silently drift ahead of the Node version CI
  actually runs on — this repo already has that drift, pre-existing and
  unrelated to any one bump.** `.github/workflows/ci.yml` pins
  `node-version: '22'` but `frontend/package.json` requires
  `@types/node@^26.x` (true both before and after the `26.3.0 → 26.5.1`
  Dependabot bump reviewed this session). This didn't fail anything —
  `@types/node` types are additive/superset in practice and typecheck was
  green in CI and confirmed clean locally — but it's worth knowing this
  mismatch exists so a future session doesn't waste time treating a real
  Node-22-vs-26-API typecheck failure as some other kind of bug if one
  ever surfaces from it. Not fixed this session (out of this task's
  scope) — either pin `@types/node` to the `^22.x` line or bump the CI
  runner's actual Node version to close the gap, whichever the project
  actually wants going forward.
- **This session's tools still have no GitHub Dependabot *security
  alerts* API access** — same real, unchanged gap every prior session
  documented for the version-bump PRs themselves (which ARE directly
  visible as branches/PRs, and were the actual subject of this session).
  Reviewing/merging the 7 open bump PRs is not the same claim as "no
  unaddressed Dependabot alerts exist" — that second claim was not and
  could not be verified this session.

## Session 25 additions — FR-15 no-show count; a fresh container has no `.env`/`APP_KEY` even after `.env.example` is copied

- **`cp .env.example .env` does NOT give you a working `APP_KEY`** — the
  example file ships with `APP_KEY=` empty, same as Session 23 already
  found for `.env.testing`'s own copy. Forgetting the immediately-following
  `php artisan key:generate --force` doesn't fail migrations or Pest (Pest
  loads `.env.testing`, which already ships a real key baked in), so it's
  easy to migrate/seed successfully and only discover the gap later, and
  confusingly: `php artisan serve` + a real HTTP request against it fails
  with `MissingAppKeyException`, but a bare `php artisan migrate` or
  `php artisan db:seed` never touches encryption and works fine either
  way. If a real `php artisan serve` (for Playwright's `webServer`, or any
  manual `curl` against the dev API) 500s immediately on the very first
  request with `MissingAppKeyException`, check `grep APP_KEY .env` before
  assuming anything about routes/controllers/middleware is broken.
- **The three Playwright E2E specs that create appointments through the
  real booking flow (`booking-flow.spec.ts`, `manage-booking-cancel.spec.ts`,
  `owner-appointment-actions.spec.ts`, `owner-admin-forms.spec.ts`) are
  only safe to run against a freshly `migrate:fresh --seed`ed `bookslot`
  dev database — running them a second time (or running one spec file
  twice) without re-seeding in between is NOT just "extra harmless test
  data," it can make a *later* run fail outright.** `owner-appointment-
  actions.spec.ts`'s own row locators match by substring + `.first()`
  (e.g. `page.locator('tr', { has: page.getByRole('cell', { name:
  'E2E NoShow', exact: false }) }).first()`) — real, deliberate, since each
  test run's customer name carries a `Date.now()` suffix precisely so
  concurrent/sequential runs don't collide on an *exact* name — but
  `.first()` picks whichever matching row sorts first by `starts_at`
  (the list's own sort order), not the row this test run just created. A
  second run leaves the first run's own same-labelled row (already
  advanced to `completed`/`cancelled`/whatever that earlier run did to it)
  sitting in the same list, and if it happens to sort earlier, `.first()`
  binds to the **stale** row instead of the new one — the click either
  lands on a row with no matching action button (nothing happens) or
  produces a status assertion failure that looks exactly like a real
  regression (a timeout waiting for a status text to appear) but is 100%
  a leftover-data artifact. **Confirmed directly this session, the hard
  way:** re-running `owner-appointment-actions.spec.ts` a second time
  without re-seeding reliably reproduced this exact failure — proven
  innocent only by re-running against `main`'s own unmodified controller/
  page (identical failure) and then again after a fresh `migrate:fresh
  --seed` (10/10 passed both times, isolated spec and the full suite
  together). **Always `php artisan migrate:fresh --seed
  --database=pgsql_migrator` (with `.env` pointed at `bookslot`, per
  Session 23's own note) immediately before the one real Playwright run
  you intend to trust** — don't chain multiple manual re-runs against the
  same seeded data and don't infer a real bug from a second run's failure
  without re-seeding and repeating first. This is a pre-existing property
  of these four spec files' own locator design (not something this
  session changed or fixed — genuinely out of FR-15's scope, and D-0054
  already established these same specs pass 10/10 against a single fresh
  seed in real CI), flagged here purely so a future session doesn't burn
  time chasing a phantom regression that's actually just stale local
  fixture data.
- **FR-15's "basic no-show count" was scoped from the requirement text
  itself, not assumed from the feature name — and the data-source
  question genuinely diverged from what a plausible-sounding guess would
  produce.** `ReleaseExpiredPendingBookingJob` (FR-05/D-0049) — the
  mechanism a prompt or a skim of the feature name might reasonably guess
  feeds a "no-show" count — only ever transitions a still-`pending_payment`
  booking to `status = 'cancelled'` (`cancelled_by: 'system'`,
  `cancelled_reason: 'hold_window_expired'`); it has no code path that
  ever writes `status = 'no_show'` and never touches an already-`confirmed`
  appointment at all. The only way `status = 'no_show'` is ever reached in
  this codebase is `Owner\AppointmentController::updateStatus()` — an
  explicit owner click (FR-07/D-0042), which is also exactly what J4 (no
  automatic no-show detection) requires. Verify this distinction directly
  against `04-data-model.md`'s state machine (`confirmed --> no_show:
  owner marks no-show` is the only incoming transition) before building
  anything that claims to count no-shows in this codebase — see D-0055 for
  the full writeup.
