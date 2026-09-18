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

## Session 26 additions — the refund endpoint; every owner route's `auth.tenant` wraps the WHOLE request in one open transaction, which breaks the moment an owner route needs to call Stripe

- **`auth.tenant` (`AuthenticateTenantUser`) is not just "auth + tenant
  resolution" — it wraps `$next($request)` itself inside
  `TenantContext::run()`, which opens a real database transaction
  (`$db->transaction(...)`, confirmed by reading `TenantContext::run()`'s
  own implementation) and does not close it until the controller action
  returns.** Every owner-route controller method before this session
  implicitly relied on that transaction already being open (none of them
  call `TenantContext::run()` themselves) — this was invisible because no
  owner route had ever needed to call Stripe before. The instant one does
  (this session's refund endpoint), running it behind plain `auth.tenant`
  would hold that transaction open across the live Stripe call, exactly
  what D-0027 forbids (previously only ever relevant to the two *public*
  Stripe-touching routes, `BookingController`/`PaymentConfirmationController`,
  which sidestep the whole problem by never using `tenant.context`/
  `auth.tenant` in the first place). **If a future session builds the
  still-unbuilt `POST /owner/appointments/{id}/balance/charge` endpoint
  (J5's off-session charge, next on the backlog) or any other owner route
  that calls Stripe, it needs the same fix this session built** —
  `auth.tenant.external` (`AuthenticateTenantUserWithoutTransactionWrap`),
  which performs the identical session-based owner auth check inside one
  short, immediately-closed `TenantContext::run()`, then continues the
  pipeline outside any open transaction with `tenant_id` left on the
  request for the controller to open its own short `TenantContext::run()`
  calls around (the exact same shape `BookingController`/
  `PaymentConfirmationController` already use). Don't rediscover this from
  scratch by tracing `AuthenticateTenantUser`'s docblock again — just reuse
  the existing `auth.tenant.external` alias and put the new route outside
  the `owner` prefix group, same as this session's refund route.
- **`.env.testing`'s `STRIPE_SECRET_KEY=sk_test_dummy_for_tests` "looks
  real" to `AppServiceProvider`'s own real-vs-fake heuristic** (`str_starts_with('sk_')`
  and no literal `PLACEHOLDER` substring) — a Feature test file that
  exercises any Stripe-calling controller method WITHOUT its own
  `beforeEach(fn () => app()->bind(PaymentIntentGateway::class, fn () =>
  new FakePaymentIntentGateway))` will silently get the REAL
  `StripePaymentIntentGateway` at test time, which then throws attempting
  a real network call against a dummy key — surfacing as a generic 502
  from whichever controller catches `Throwable` around the Stripe call,
  not an obviously-Stripe-shaped error. `BookingControllerTest`/
  `PaymentConfirmationControllerTest` already had this binding; a new test
  file for any other Stripe-calling endpoint needs the identical
  `beforeEach()` — confirmed by hitting exactly this failure once while
  writing this session's refund tests, before adding the binding.
- **`04-data-model.md`'s payment state machine, read literally, makes a
  refund one-shot per payment, not incrementally toppable-up.** The
  mermaid diagram draws `succeeded --> refunded` and `succeeded -->
  partially_refunded` as the only two outgoing edges from `succeeded`, and
  both `refunded` and `partially_refunded` themselves go straight to `[*]`
  (terminal, no further outgoing edge at all). A first implementation
  attempt this session allowed a second partial refund to "top up" an
  already-`partially_refunded` payment up to its original remaining
  balance — that reads naturally from endpoint 5's own contract wording
  ("the remaining refundable balance"), but directly contradicts the
  documented state machine once `partially_refunded` is reached. Caught by
  a test written specifically to exercise that second call, not by
  inspection — the test expected `200` and got `409` because eligibility
  is (correctly) gated on `payments.status === 'succeeded'` alone, which a
  first partial refund already moves away from. Fixed by treating "the
  remaining refundable balance" as simply the payment's own `amount` (accurate
  precisely because a `succeeded` payment can only ever be refunded once,
  so there is never a prior successful refund to subtract at the point
  this check runs) rather than building a running-balance calculation the
  state machine doesn't actually support. If a future session wants real
  incremental multi-refund support, `04`'s diagram needs a new,
  deliberately-reasoned transition added first — don't infer one from the
  contract prose alone, which is not itself the authoritative state
  machine.
- **`refunds` and `payments` were already both present in
  `config('tenancy.tenant_scoped_tables')` and RLS-enabled** (from the
  original migration set, long before this session) — a refund endpoint
  needed zero new tenant-scoping/RLS work, only correct use of the
  existing `TenantContext::run()` pattern. Don't assume a new
  money-movement table needs new RLS wiring without checking
  `config/tenancy.php` first; it may already be covered.

## Session 27 additions — the off-session balance-charge endpoint; `composer install --prefer-source`'s real failure point is `phpstan/phpstan` specifically, not whatever package composer's own progress output happens to be printing last

- **When `composer install --prefer-source` fails with "Could not
  authenticate against github.com," don't assume the package composer's
  progress bar was printing when it died is the actual culprit — re-run
  with `-vvv` and read the LAST HTTP line before the exception, not the
  last "Syncing ... into cache" line.** This session's plain (non-`-vvv`)
  output made it look like `stripe/stripe-php` (the very last package
  listed before the crash) was the one failing to authenticate — a
  reasonable but wrong first guess, since this task's own scope is
  entirely Stripe-shaped and a Stripe-specific git-auth wall would have
  been a very different, harder problem. Re-running with `-vvv` showed the
  real failing call was `[403] https://api.github.com/repos/phpstan/
  phpstan/zipball/...` — `phpstan/phpstan` (this repo's already-documented
  no-`source`-in-Packagist-metadata gap, via `larastan/larastan`), and
  `stripe/stripe-php` itself had already cloned via git successfully
  several lines earlier in the same output. The existing documented
  workaround (temporarily remove `larastan/larastan` from `require-dev`,
  `composer update --prefer-source`, then `git checkout --
  composer.json composer.lock` before any commit) applied unchanged and
  fixed it — no new workaround was needed, but the diagnosis step (`-vvv`,
  reading the actual failing URL) is worth keeping in mind before spending
  time on a wrong theory about which package is actually blocked.
- **A fresh container this session had neither RabbitMQ installed at all
  (not just stopped) nor any Postgres roles/databases** — consistent with
  every prior session's own finding that the container is genuinely fresh
  each time, not something to assume persisted from Session 26. All of
  Session 21/22's documented steps (create `bookslot_migrator`/
  `bookslot_app` roles, `createdb` both databases as the `postgres` OS
  user via `sudo -u postgres`, the three `GRANT`/`ALTER DEFAULT
  PRIVILEGES` statements against BOTH databases, `apt-get install
  rabbitmq-server` + `service rabbitmq-server start` + `rabbitmqctl
  add_user bookslot ...`) were needed from scratch again, in that order,
  before `migrate:fresh --seed` or any Feature test could run.
- **This codebase already had every piece of "off-session payment method"
  plumbing J5 needs before this session — reusing it correctly meant
  reading D-0006/D-0010/D-0031 closely, not building anything new.** The
  deposit PaymentIntent (`StripePaymentIntentGateway::create()`, D-0006)
  already sets `setup_future_usage: off_session`; `PaymentConfirmationController`
  (D-0033) already backfills the resulting payment method's ID into
  `payment_mandates.stripe_payment_method_id` (D-0031's nullable-with-
  backfill column); and — the one genuine Stripe-API-mechanics question
  worth resolving explicitly rather than assuming — neither `create()` nor
  any table in `04-data-model.md` has ever involved a Stripe *Customer*
  object (no `stripe_customer_id` column anywhere; `customers` is this
  app's own unrelated table). Stripe's own documented pattern for
  reusing a saved card without a Customer object (confirming a *new*
  PaymentIntent directly against a previously-used `payment_method` ID via
  `off_session: true, confirm: true`) matches this codebase's existing
  design exactly — building the balance charge was "add one gateway method
  that reuses the existing saved value," not "design new payment-method-
  saving plumbing." If a future session is asked to build something that
  looks like it needs a new saved-payment-method mechanism, check
  `payment_mandates.stripe_payment_method_id` and D-0031 first — it may
  already exist.
- **Stripe's off-session confirm failures (decline, SCA-authentication-
  required) surface as a thrown `Stripe\Exception\CardException`, not a
  returned `requires_action` PaymentIntent status** — this is different
  from the on-session confirm flow `PaymentIntentGateway::retrieve()`
  already reads a status back from, and it's the reason
  `chargeOffSession()` has a different contract from `create()`/`refund()`
  on this codebase's own `PaymentIntentGateway` interface: it catches that
  specific exception itself and reports the outcome via a returned value
  object, rather than letting the caller's generic `catch (Throwable)`
  turn every failure into a `502`. Never verified against a real Stripe
  account (D-0036, permanent) — written to match Stripe's own published
  API docs for this flow, and the fake-tier tests are the only thing that
  actually exercises the branching. If a future session touches this
  gateway method, keep the "decline/auth-required is an expected
  `OffSessionChargeResult`, only a genuine provider failure still throws"
  distinction — collapsing the two would either surface real declines to
  the frontend as `502`s (wrong per `05-api-contracts.md`'s own documented
  `200`-with-`failed`-status contract) or silently swallow a real outage
  as if it were an ordinary decline.
- **`payments.status`'s "terminal" states are not the same set for every
  `payments.type`, and treating them as if they were is a real trap.** A
  `deposit`-type payment's `refunded`/`partially_refunded` are terminal by
  `04`'s own diagram (D-0056's refund is correctly one-shot). A
  `balance`-type payment's `failed` is explicitly NOT terminal — J5's own
  requirement text frames a decline as retriable ("the owner is shown a
  clear 'collect in person' fallback action... rather than a silent
  failure"), and nothing in `04`'s payment-state-machine diagram draws
  `failed` as terminal for either payment type. This session's balance-
  charge eligibility check only blocks a retry on `succeeded`/
  `paid_manually`/`processing`, deliberately not `failed` — copying
  refund's "any non-refundable-shaped status blocks it" pattern verbatim
  would have been wrong here, not just inconsistent-looking.

## Session 28 additions — the Stripe Connect onboarding-link endpoint; a `composer` scripts-vs-binary gotcha in this root sandbox

- **`composer test:fast`/`composer analyse`/any other `composer <script>`
  that wraps a vendor binary aborts outright when run as this sandbox's
  root user, even after Session 20's `sudo -u postgres`-style workarounds
  are irrelevant here** — the failure is `Aborting as no plugin should be
  loaded if running as super user is not explicitly allowed`, which looks
  like a broken install but isn't: it's Composer's own safety gate against
  running as root, and it blocks the *script wrapper* specifically, not
  the underlying tool. Calling the vendor binary directly
  (`./vendor/bin/pest --exclude-group=queue-broker`,
  `./vendor/bin/pint --test`) has never hit this and needs no workaround —
  confirmed working fine all session. Only reach for
  `COMPOSER_ALLOW_SUPERUSER=1 composer test:fast` (etc.) when you
  specifically want to run the exact named `composer.json` script (e.g. to
  double-check it matches what CI's own step invokes) rather than just the
  test suite itself.
- **This session's container was fresh in exactly the ways Sessions
  21-27 already documented** (no `.env`, no Postgres roles/databases, no
  RabbitMQ installed) — every documented step from those sections applied
  unchanged and worked first try. Nothing new to add there; recorded here
  only so a future session doesn't waste time re-verifying that the
  existing instructions still hold — they do.
- **`tenants` (the tenancy-boundary table, deliberately outside RLS —
  `04-data-model.md`) already had both Connect-related columns
  (`stripe_connect_account_id`, `stripe_onboarding_status`) since the
  original Session ~8 migration set, long before any Connect code
  existed.** Building the actual onboarding-link/status endpoints (D-0058)
  needed zero new columns on that table — only a new supporting unique
  index. Same lesson D-0056 already recorded for `refunds`/`payments`:
  check the existing schema before assuming a new feature needs new
  migrations.

## Session 32 additions — owner-admin form validation errors; a fresh
  container has no `frontend/node_modules` either, and `npx vue-tsc`
  fetches a broken standalone copy if you don't `npm ci` first

- **The public booking page (`frontend/app/pages/tenants/[slug]/index.vue`)
  already had the right pattern for this** — a `fieldErrors: Record<string,
  string[]>` ref populated from `apiErrorBody(e).fields` on a
  `VALIDATION_FAILED` 422, rendered as `<span class="field-error">` next to
  each input. **Every owner-admin form (services, staff, working hours,
  availability exceptions, appointment cancellation) never adopted it** —
  each one's `describeError()`/catch block reads only `apiErrorBody(e).error`
  and shows a single generic "Please check the highlighted fields." banner,
  silently discarding the exact same `fields` object the backend already
  sends them (confirmed directly: `bootstrap/app.php`'s `ValidationException`
  render callback returns `{"error": "VALIDATION_FAILED", "fields": {...}}`
  for every `api/*` route, admin or public, unchanged since it was written).
  This was a real, confirmed gap, not a hypothetical one — none of these
  five forms had any inline field-error display before this session.
- **Built `frontend/app/composables/useFormErrors.ts`** as the one shared
  place this logic now lives, rather than five slightly different
  re-implementations: `applyError(e, describeError)` sets both a
  `formError` banner and a `fieldErrors` map from a caught error in one
  call, `fieldError(field)` reads the first message for a field,
  `otherFieldErrors(knownFields)` surfaces any field the form has no
  dedicated input for (so nothing the backend reports is ever silently
  dropped), and `clear()` resets both before a new attempt. Precedence
  decision (documented in the composable's own docblock): the server's 422
  is authoritative — `applyError` always *replaces* the previous attempt's
  errors wholesale rather than merging, so a resubmission never shows a
  stale field error for something the latest response says is now fine.
  Native HTML `required`/`min`/`max` (already used throughout these forms)
  still gives immediate client-side feedback and blocks a submit before it
  reaches the server, but only for what the browser can check itself — it
  never suppresses or overrides a server error, since it can't express this
  codebase's cross-field/uniqueness rules (e.g. `ServiceController::update()`'s
  deposit_type/amount invariant) at all.
- **The weekly-working-hours `PUT` endpoint's validation errors are the one
  genuinely tricky case**, and worth flagging for whoever touches this
  page next: `WorkingHourController::replace()` validates
  `working_hours.*.start_time`/`working_hours.*.end_time` against the
  *array index in the submitted payload* (only enabled days, in week
  order), which is **not** the same as the day-of-week index the UI keys
  its rows by once any day is disabled — day 0 (Sunday) disabled and only
  Monday (day 1) enabled means Monday is payload index 0, not 1. Mapping a
  `working_hours.0.end_time` error back to the visible Monday row needed
  an explicit index-translation helper (`enabledDayIndexes`/
  `workingHourFieldError()` in `availability/index.vue`) — a naive
  `fieldErrors['working_hours.' + dayOfWeek + '.end_time']` lookup would
  silently show nothing, or worse, show the wrong day's error, the moment
  any earlier day in the week is disabled.
- **A fresh container has no `frontend/node_modules` at all** (this
  session's container had none, same "genuinely fresh every session"
  pattern Sessions 21-28 already documented for the Postgres/RabbitMQ
  side) — running `npx vue-tsc --noEmit` before `npm ci` doesn't just fail,
  it makes `npx` silently fetch and run a *different, unpinned* `vue-tsc`
  version from the registry, which then hits the exact
  `ERR_PACKAGE_PATH_NOT_EXPORTED` failure Session 24 already diagnosed
  (`vue-tsc` resolving TypeScript's removed `./lib/tsc` subpath) — except
  this time it's not a real TS 7 mismatch, it's `npx` ignoring the
  project's own pinned, compatible `vue-tsc`/`typescript` versions in
  `frontend/package.json` entirely because they were never installed.
  `cd frontend && npm ci` first, then `npx vue-tsc --noEmit` (or `npm run
  typecheck`) resolves to the project's own pinned copy and passes clean.
  Don't mistake this for a real Session-24-style TS/vue-tsc incompatibility
  without first checking whether `frontend/node_modules` exists at all.

## Session 33 additions — verifying Session 32's owner-admin form validation
  branch by actually running the suites; a real Vitest flake found and
  fixed, Playwright itself came back clean

- **Session 32's work was never on `main`** — it was its own branch,
  `claude/admin-form-validation-errors-8byoq2`, one commit ahead of the
  same `main` tip this session started from. The session-handoff file's
  own latest amendment (Session 31, merge reconciliation) doesn't mention
  it at all, because it's a sibling branch, not something Session 31 could
  have known about. Built this session's own branch on top of it
  (`git checkout -B <branch> origin/claude/admin-form-validation-errors-8byoq2`)
  rather than on `main`, since the task is to verify *that* branch's
  markup, not to redo the work against a base that doesn't have it yet.
- **`npx vitest run` (the whole suite, all 7 files as separate worker
  processes) reproducibly failed 1 of Session 32's own 4 new form-test
  files, but the same file passed every time run in isolation
  (`npx vitest run tests/unit/ownerServicesForm.test.ts`).** Root cause:
  every one of Session 32's new tests used a fixed
  `await new Promise((resolve) => setTimeout(resolve, 20-50))` to "wait"
  for an in-process mock HTTP server round-trip (CSRF-cookie fetch, then
  the real POST/PUT) to finish before asserting on the rendered error
  text. That fixed delay is a race, not a guarantee — under the CPU
  contention of 7 vitest workers spawning at once (the full-suite case),
  50ms was sometimes not enough for both round-trips plus the Vue
  re-render to complete, so the assertion ran while the form was still
  mid-request (`Saving…` still showing, no error text rendered yet) and
  failed on wording that was never wrong. Confirmed directly: reran the
  full suite 5 times in a row after the fix below, 20/20 tests green every
  time; before the fix, at least 1 of 5 runs failed, each time on a
  different file/assertion depending on which worker got starved.
- **Fix: added `frontend/tests/unit/support/waitFor.ts` (`flushUntil`,
  a small poll-until-predicate-or-timeout helper) and replaced every fixed
  post-action `setTimeout` wait in the four new form-test files
  (`ownerServicesForm.test.ts`, `ownerAvailabilityForm.test.ts`,
  `ownerAppointmentCancelForm.test.ts`) with a poll on the actual condition
  the test cares about** (the expected error text appearing, or
  disappearing on resubmission, or the target `.day-row`/input existing
  after initial mount) — both the initial-mount waits and the post-submit
  waits, since both are subject to the identical race. This is a test-
  harness fix only; none of Session 32's actual `.vue`/`useFormErrors.ts`
  application code changed, and the fix doesn't paper over a slow app —
  `flushUntil`'s default 2s timeout still fails the test loudly if the
  condition is genuinely never met.
- **Playwright's `owner-admin-forms.spec.ts` needed zero changes** — it
  already used `getByLabel(...)`/`getByRole(...)` locators throughout
  (role/label-based, not structural), and Session 32's markup change
  (wrapping each input in a `<label>...<input/><span v-if="fieldError(...)">`
  block) doesn't alter any label's accessible name in the no-error case
  (the `<span>` simply doesn't render when `v-if` is false), so every
  existing locator kept resolving to the same element. Ran the full
  Playwright suite (`npx playwright test`, all 4 spec files, 10 tests)
  twice, each time against a freshly `migrate:fresh --seed`ed `bookslot`
  dev database per Session 25's own documented precaution — 10/10 passed
  both times. No locator fix, no regression, nothing to harden here; the
  fragility this session actually found was in the Vitest harness, not in
  any Playwright locator.
- **Backend fast gate unaffected by this branch, as expected (it touches
  only `frontend/`)**: `./vendor/bin/pest --exclude-group=queue-broker`
  205/205, `./vendor/bin/pint --test` clean, `npx vue-tsc --noEmit` clean.
  Confirmed rather than assumed, since the task explicitly asked for a
  real run, not an inference from the diff's file list.

## Session 36 additions — owner-admin UI triggers for refund/balance-charge/
  Connect/erasure/re-invite; a Vitest false-negative found by real-browser
  verification, and dev-server host-binding/process-cleanup notes

- **A component test whose mock server always returns the SAME fixture
  regardless of what the mutating request under test actually did will not
  catch a bug that only manifests after that request's own follow-up
  reload.** This session's first version of the appointment-detail page's
  new "Refund deposit" section gated the entire section — including the
  post-refund success message — on there still being a `succeeded` deposit
  payment. The page reloads its own detail data after a successful refund
  (so the payments table reflects the new `refunded` status immediately),
  which makes that condition go false the same render cycle the success
  message was supposed to appear in — hiding it instantly. The Vitest test
  written first (mounting the page against a local mock HTTP server, same
  pattern as every other owner-admin form test in this repo) did not catch
  this, because its mock route for the appointment-detail GET always
  returned the same static pre-refund fixture, every time it was called,
  including the reload that follows the refund POST — so the "is there
  still a refundable deposit" condition the bug depended on never actually
  changed state inside that test. It was found only by driving the real
  page against the real Laravel backend in a real (pre-installed) Chromium
  browser and watching the message flash and disappear. **If a mutating
  action's own test also exercises a reload/refetch afterward, make the
  mock's response for that GET route reflect the POST/PATCH's expected
  effect (change the route's returned body once the mutation "happens"),
  not the same fixture every time** — otherwise the test can look
  thorough while structurally being unable to catch exactly this class of
  bug. This repo's fast Vitest suite is fully mocked-HTTP by design (no
  real backend), which is why the real-browser pass remains genuinely
  load-bearing verification, not a formality, for any change to a page
  that reloads its own state after a mutation.
- **Starting `php artisan serve`/`npm run dev` for a manual/ad hoc
  real-browser check needs the same explicit `--host=localhost` (never the
  default bind, and never `127.0.0.1`) that `playwright.config.ts`'s own
  `webServer` entries already use** — Session 23's documented
  localhost-vs-127.0.0.1 cookie-scoping gotcha applies identically to a
  manually started pair of dev servers, not just to Playwright's own
  auto-launched ones. Starting either server without `--host=localhost`
  first (this session's own first attempt) produced a server that
  technically answered on `localhost` for a plain `curl` health check
  (loopback resolves the same either way) but was still the wrong bind for
  the Sanctum SPA cookie flow once a real browser session got involved.
- **`pkill -f "artisan serve"` (or any `pkill`/broad-pattern kill) run from
  this Bash tool can itself return a nonzero/unusual exit code (144 seen
  this session) even when it successfully signals the target** — don't
  chain further commands after it with `&&`, since that can make an
  otherwise-successful cleanup step look like a failure and abort the rest
  of the chain. Check with a fresh `ps aux | grep ...` afterward (a
  separate command) rather than trusting the exit code of the `pkill`
  invocation itself; if processes remain, `kill -9 <pid>` on the specific
  PIDs from that `ps` output is the reliable fallback, used this session.
- **A dev-server manual verification pass belongs in a throwaway spec file
  placed temporarily inside `tests/e2e/` (so it can reuse
  `support/booking.ts`'s existing fixtures/login helpers and
  `playwright.config.ts`'s existing project config) and deleted before
  committing** — trying to run a Playwright spec from outside `testDir`
  (e.g. the scratchpad directory) fights the config for no benefit, since
  the config's own `webServer`/`baseURL`/`launchOptions` are exactly what
  a real manual check needs too. Remember to `rm` both the temp spec file
  and the `test-results/`/`playwright-report/` directories it generates
  before checking `git status` — neither is meant to be committed, and
  neither is gitignored by name alone (they simply aren't tracked because
  no prior session ever committed one), so an inattentive `git add -A`
  could pull them in.
- **When a manual verification flow reuses the SAME booking/appointment
  for two actions that are mutually exclusive on the backend (e.g. this
  session's first attempt: refunding a deposit, then trying to
  balance-charge the same appointment), the second action fails against
  the real backend's own eligibility rule (here, `DEPOSIT_NOT_CAPTURED`,
  since the deposit was already refunded) — not a bug, just an unrealistic
  test scenario.** Create a separate booking per action being manually
  verified when the actions have real state preconditions that conflict,
  the same way the Pest/Playwright suites already do with their own
  per-test fixtures.
