# Testing Strategy
> Purpose: what we test, at which level, and why that is sufficient.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-26 (Session 4 — concurrency/slot-integrity cases added after D-0008's DDL correction; amended Session 5 — framework decided, CI gate and E2E scope settled; amended Session 6 — the missing D-0008 invariant test added, plus one D-0006 gap; amended Session 7 — D-0021 confirm-payment token tests, the D-0010 erasure test D-0022 made writable, and `rebooking_invite` coverage; amended Session 9 — the two verification gaps Session 8's handoff left open (malformed/non-existent GUC values; an application-layer scope guard) closed by execution, and real HTTP-level tenant resolution wired for the first time; amended Session 10 — Sanctum SPA auth tested with a real CSRF-rejection case, booking creation built and tested end to end including the true multi-process concurrency case this section had flagged as missing since Session 8, and D-0027's transaction boundary proven against a real Stripe-touching controller; amended Session 16 — availability's derived-slot algorithm covered, plus this project's first real cross-origin/CSRF proof against a live server, outside the Pest suite entirely; amended Session 17 — owner appointment list/status coverage added, and a real Pest blind spot found and recorded: nested TenantContext::run() calls inside one test transaction mask an auth-ordering bug a live server exposes immediately)

## Amendment (Session 17, 2026-08-26) — owner dashboard tests added; a real Pest blind spot found and recorded

**Owner appointment list/status (D-0042).** `tests/Feature/Api/OwnerAppointmentControllerTest.php`: an owner sees appointments across every staff member in their tenant (not narrowed to "own bookings" the way D-0013's staff rule is), with customer/service/staff names and deposit status populated; marking a `confirmed` appointment `completed` or `no_show` succeeds and writes exactly one `booking_events` row (`status_changed`, `actor_type: owner`) with no `payments`/`refunds` side effect; marking a `pending_payment` or already-`completed` appointment is rejected `409 INVALID_STATUS_TRANSITION`; a cross-tenant appointment id gets a plain `404`, not `403` (see D-0042's correction to `05`); a staff-role session gets `403` from the owner-only route; an unauthenticated request gets `401`.

**A real, load-bearing methodological finding — not just a code bug.** Building the above and testing it against a real, separate `php artisan serve` process (not just Pest) for the first time found that a returning owner/staff session could never re-authenticate on any request after the first (D-0043, `09-decision-log.md`) — a bug present in every owner/staff route since D-0029 (Session 10), and **every existing Pest test for those routes passed the whole time.** The reason is D-0025's own already-documented mechanism, applied here for the first time to explain a *masked bug* rather than a *hardened defense*: Pest wraps a whole test in one outer transaction, so a test's own `TenantContext::run()` calls (used to seed fixtures) only open savepoints, and a `set_config(..., true)` GUC value set inside one doesn't reset when that savepoint releases — it persists, at the database level, for the rest of the test. This let every prior owner/staff Feature test's second HTTP request "succeed" against a GUC state a real, separate live-server request could never have. **This is a real, standing limitation of this project's own primary verification method, not a one-off — recorded here so a future session doesn't read "Pest passes" as proof of any property that depends on tenant-context *not* already being set from earlier in the same test.** No new automated test was added specifically for the ordering bug itself (D-0043's fix is structural — one consolidated middleware with no cross-middleware ordering left to get wrong — the existing Feature tests exercise it correctly now that the fix is in place); the live-server reproduction is documented in `12-session-handoff.md` and `09` D-0043 instead, since Pest cannot reproduce the failure mode that revealed it.

**`composer ci:check` after this session: 76/76 Pest tests, 414 assertions, Pint/PHPStan clean.** `composer test:tenant-isolation`: 20/20, ~5.5s, comfortably under D-0017's 60-second budget.

## Amendment (Session 16, 2026-08-26) — availability coverage, and this project's first real live-server proof

**Availability (D-0039).** `tests/Feature/Api/AvailabilityControllerTest.php` covers `GET /api/tenants/{slug}/availability`'s derived-slot algorithm: working-hours-bounded slot generation at the configured increment; an existing appointment's *buffered* occupancy excluding every overlapping candidate, not just its literal window (the case worth calling out — it proves the buffer math, not just the working-hours math); a full-day `availability_exceptions` block removing exactly that date; unknown `service_id`/`staff_id` → `404`; an omitted `staff_id` searching every active staff member; a lookahead past the configured max → `422`. All against real Postgres, real `staff_working_hours`/`availability_exceptions`/`appointments` rows — no SQLite, per this section's own standing rule.

**A real live-server proof, not just Pest — this project's first.** This session's actual deliverable (the Nuxt frontend, `frontend/`) needed proof against the real running application, not the test suite's in-process request simulation: `php artisan serve` + the real Postgres/Redis containers + a real `db:seed`-created tenant, driven by real `curl` requests replicating exactly what the frontend's own `apiFetch()` composable sends (same headers, same CSRF cookie dance, same request bodies) — services → availability → mandate → booking → confirm-payment, ending in a real `confirmed` appointment verified by a direct `TenantContext::run()` database read afterward, not just the HTTP response. This surfaced two real gaps no Pest run had ever hit (`.env` missing `APP_KEY`; the local PHP CLI's missing `phpredis` extension breaking Sanctum's stateful session) and one real application-behavior finding (D-0041: public endpoints still require the Sanctum CSRF cookie) — all fixed or documented this session, none silently worked around. See `12-session-handoff.md`'s Session 16 amendment for the full step-by-step account.

**`composer ci:check` after this session: 68/68 Pest tests, 377 assertions, Pint/PHPStan clean.**

## Amendment (Session 10, 2026-08-25) — auth, booking creation, and D-0027 all proven by execution

**Auth (D-0029).** `tests/Feature/Api/AuthControllerTest.php` proves login/logout, role-gating (401 unauthenticated, 403 wrong role), and — the one worth calling out specifically — a **real** CSRF-rejection test. Laravel's own CSRF middleware (`PreventRequestForgery::runningUnitTests()`) unconditionally skips verification whenever `app()->runningInConsole() && app()->runningUnitTests()` — both true for every ordinary Pest run, meaning a naive test asserting "a request without a CSRF token is rejected" would pass trivially without the check ever running. `tests/Support/AuthTestHelpers.php`'s `disableConsoleCsrfBypass()` forces `runningInConsole()` to `false` via reflection (no public API exists for this) for the specific tests that need the real path. **Verified the workaround itself matters**, not just documented it: removed it, watched the "rejected" test's assertion flip from `419` to `200` (proving CSRF was silently not being checked), then restored it. `StaffAppointmentControllerTest.php`/`AdminAppointmentControllerTest.php` prove the two new tenant-resolution mechanisms (`resolve.tenant.from-user`, `resolve.tenant.impersonate`) scope correctly — a second staff member's appointments never appear; impersonating tenant A never leaks tenant B's rows.

**Booking creation (D-0030).** `tests/Feature/Api/BookingControllerTest.php` (happy path incl. token verification and mandate-text equality with the pre-submission endpoint; unknown-service 404; mandate-not-accepted 422) and `MandateControllerTest.php` (the pre-submission endpoint renders identically to what booking creation stores — the one-shared-renderer requirement, proven, not asserted). `BookingConcurrencyTest.php` closes the "Concurrency and slot integrity" section's headline case, open since Session 8 — see that section below for the mechanism (real, separate OS processes; a single PHP test process can't produce genuinely separate database connections on its own).

**D-0027's transaction boundary, proven against a real Stripe-touching controller for the first time.** `BookingControllerTest.php`'s two D-0027 cases bind `tests/Support/FakePaymentIntentGateway.php` (this project's instance of the already-documented "faked Stripe client by default" tier below) over the `PaymentIntentGateway` interface in the container: one makes the fake throw and asserts the appointment TX1 already committed is still present in the database (now `cancelled` by `BookingController`'s own deliberate cleanup, never silently rolled back or missing) with no `payments`/`payment_mandates` row (TX2 never ran); the other makes the fake sleep a full second and asserts the request still completes successfully. `09-decision-log.md` D-0027 is amended from *raised, not decided* to **accepted** on the strength of this.

**Real Stripe test-mode credentials were not available this session** (see `12-session-handoff.md`) — the real `StripePaymentIntentGateway` (stripe-php SDK) is built and wired as the default binding, but this session's suite only exercises it via the fake. Running booking creation against a real Stripe test-mode Connect account is a named, real open item, not claimed as covered.

## Amendment (Session 9, 2026-08-24) — GUC edge cases executed, an app-layer scope guard added

**V1 — malformed and non-existent tenant values, executed against real PostgreSQL 17.11 (`tests/TenantIsolation/GucValueEdgeCasesTest.php`), literal results:**

| Case | Behavior | Why |
|---|---|---|
| `current_setting` returns a non-numeric/non-uuid string (e.g. `'not-a-uuid-at-all'`) | **Throws** — `QueryException` wrapping SQLSTATE `22P02` (`invalid input syntax for type uuid`) | `NULLIF(value, '')` only intercepts the empty string (D-0025); any other non-uuid string still reaches the `::uuid` cast and fails there, the same way D-0025's own writeup already described for the general case — this session executed it as its own case rather than inferring it |
| `current_setting` returns a well-formed uuid for a tenant that **does not exist** in `tenants` | **Zero rows, no error** — the comparison `tenant_id = '<uuid>'` is a valid, ordinary comparison that simply matches nothing | Distinct from the malformed case: a syntactically valid uuid never reaches the cast-failure path at all, regardless of whether a `tenants` row with that id exists — RLS has no FK-validity requirement of its own |
| `current_setting` returns a well-formed uuid for a **different, real** tenant (the actual cross-tenant case) | **Zero rows, no error** — same mechanism as the row above, distinguished only by whether the uuid happens to belong to a real tenant | Confirms the "non-existent" and "cross-tenant" cases are the same database-level behavior for a different reason each time, worth two separate tests per this session's instruction even though the assertion shape is identical |

**Application consequence, stated plainly:** the only way a malformed value reaches the GUC in the wired paths this session adds is an application bug — every real path (slug lookup via `Tenant::where('slug', ...)`, or a verified signed token's own `tenant_id` claim, itself originally written from a real `Tenant::id`) only ever supplies a value that was, at some point, a real UUID read from the `tenants` table. No input-validation code was added defensively against the malformed case for that reason (per this pack's own "don't validate what can't happen" discipline) — if it ever occurs, it now surfaces as an uncaught `QueryException` (a 500), not a silent empty result, which is the correct place for it to surface (loud, not swallowed) precisely because it would mean a real bug upstream of tenant resolution, not a normal runtime condition.

**V2 — application-layer scope guard, `tests/TenantIsolation/ModelScopeGuardTest.php`.** Parameterized the same way `RlsManifestTest` is (over the live `app/Models` directory and `config('tenancy.tenant_scoped_tables')`, not a hand-maintained list): every concrete model class backed by a manifest table must use `BelongsToTenant` (or, for `User`, register `UserTenantScope` directly, per `04`'s documented platform-admin exception). **Demonstrated failing, not just written:** added a throwaway `App\Models\UnprotectedDemoModel` pointed at the real `services` table without the trait, ran the suite, observed the exact failure —

> `App\Models\UnprotectedDemoModel is backed by tenant-scoped table [services] but does not use App\Models\Concerns\BelongsToTenant.`

— then deleted the file and confirmed the suite passes clean again (25 assertions). This is RLS-manifest-guard-style verified, not assumed.

**What this guard catches that `RlsManifestTest` doesn't, and vice versa:** `RlsManifestTest` proves the database-level backstop holds regardless of application code. `ModelScopeGuardTest` proves the *first* of D-0005's two layers (the Laravel global scope) is actually wired on every model that needs it — a model missing `BelongsToTenant` would still be protected by RLS on read, but would silently skip the scope's auto-fill-`tenant_id`-on-create behavior (`BelongsToTenant::bootBelongsToTenant()`), meaning every create call site for that model would have to remember to set `tenant_id` explicitly — exactly the "don't trust every call site" failure mode the trait exists to close. Neither test is redundant with the other.

**The wired request path, end to end (`tests/Feature/Api/`) — the first Feature-layer tests in this repository.** Session 8 built the tenant-context mechanism and tested it by calling `TenantContext::run()` directly; this session wires it into a real HTTP request lifecycle (`ResolveTenantFromSlug`/`ResolveTenantFromSignedToken` → `SetTenantContext` → controller) and tests it the same way real traffic hits it — through `Pest\Laravel\getJson()`/`postJson()`, not a direct method call:

- `ServicesControllerTest.php` — the slug mechanism: correct tenant isolation, an unknown slug, and a soft-deleted tenant's slug both failing closed identically (`404 NOT_FOUND`).
- `ManageBookingControllerTest.php` / `PaymentConfirmationControllerTest.php` — the signed-token mechanism (D-0009/D-0021/D-0026), covering exactly the cases this file already named as writable once D-0021 landed: a bad-signature/wrong-purpose/expired token all render identically (`404 INVALID_OR_EXPIRED_TOKEN`); a token minted for one tenant's appointment cannot be made to act on another's; retry/double-submit while `pending_payment` and the post-`confirmed`/post-`cancelled` idempotent-echo/`409 BOOKING_EXPIRED` cases.
- `SequentialRequestTenantContextTest.php` — the D-0025 condition, for real: Pest's Feature-test client runs entirely in-process, so sequential requests within one test genuinely reuse the same physical connection. Covers two tenants' requests interleaved, a failed-resolution request followed immediately by a real one, and a slug-resolved request interleaved with a token-resolved request for a different tenant — all on the one reused connection.

Owner/staff/platform-admin authenticated paths have no Feature tests because they have no controllers — `05-api-contracts.md`'s Session 9 amendment records why (auth mechanics still undecided), and `12-session-handoff.md` carries it as an open item.

**PHPStan/Larastan and scope bypasses — recorded as a named, bounded item, not left implicit.** `phpstan.neon.dist` runs Larastan at **level 5**. Level 5 (or any static-analysis level, including the highest available) **cannot** catch a model missing `BelongsToTenant` — this isn't a type error or a language-level violation static analysis is built to find; it's an application-specific behavioral contract ("this model's table happens to require this trait") that has no type signature to check against. Raising the PHPStan level would improve type-safety elsewhere (stricter generic hints, no untyped array/object access, etc.) but would do nothing for this specific gap — `ModelScopeGuardTest` (this session) is the actual fix, at the test-suite layer, not a static-analysis one. Named explicitly so this doesn't silently stay assumed-covered: **raising the Larastan level is not on this session's plan and is not proposed as a fix for scope-bypass risk** — if a future session wants it for its own (legitimate, type-safety) reasons, that's a separate decision with its own cost (fixing whatever new level-6+ warnings appear across `app/`, `database/`, `tests/`), not something this session commits to or against.

## Amendment (Session 7, 2026-08-24) — three rulings made writable, plus a mechanism check

D-0021 (confirm-payment's booking-scoped token), D-0022 (the erasure
carve-out for `payment_mandates`), and D-0023 (`rebooking_invite`) each
make a previously-unwritable test case writable — added in place, in the
sections they fit (new cases in Tenant isolation suite for D-0021; a new
case in Payment flows for D-0022; a new Notification coverage section for
D-0023), not stacked here.

**Re-check requested this session: does any existing test in this file
assert a mechanism D-0021 changed?** No. Searched every existing case for a
reference to `/confirm-payment` or to an unscoped-`appointment_id`-as-tenant-
resolution mechanism: none exists. The tenant-isolation suite's
"cross-tenant ID guessing" case is explicitly scoped to *authenticated*
owner/staff endpoints taking a resource ID (`05`'s endpoint table minus the
public section) — `/confirm-payment` was never a row in that generated list
in the first place, being public and unauthenticated, so nothing there
needs correcting. No other section mentions this endpoint. Nothing in this
file required invalidation.

## Amendment (Session 6, 2026-08-24) — the D-0008 invariant test that was never written, plus a D-0006 gap

D-0012 (Session 5) found that D-0008's buffer-snapshot source column had
never actually been added to the schema — meaning D-0008's core guarantee
(a studio's later buffer-config change never retroactively alters an
already-created booking) was unimplementable as written for four sessions,
undetected, because every existing test asked whether the schema was
internally consistent and none asked whether it delivered that specific
property. See `09-decision-log.md`'s postscript to D-0012 for the full
reasoning. The gap in this file: the "Buffer enforcement under race" and
"Studio changes its buffer" cases already present test *adjacent* behavior
(concurrent buffer races; the schema's grandfathering description) but
never state the guarantee directly as an assertion. Added below, under
Concurrency and slot integrity.

Also added: one gap from reviewing D-0006, D-0009, and D-0010 for whether
each decision's *stated purpose* — not just its mechanics — has a
corresponding test (full review recorded in this session's report, not
duplicated here in full):
- **D-0006 (off-session balance charge):** gap found and added below (the
  balance charge must reuse the exact saved payment method from the
  appointment's `payment_mandates` row, not a newly-collected or
  substituted one) — clear-cut and directly testable against the faked
  Stripe client already used at this layer.
- **D-0009 (fail-closed tenant context):** reviewed, no gap — the existing
  tenant-isolation suite (global-scope bypass, queue jobs without context,
  jobs retried after a context change, cross-tenant ID guessing, admin
  impersonation, unauthenticated public-path spoofing) already tests the
  stated purpose directly, not just the mechanism.
- **D-0010 (mandate evidence retention):** gap found, **not** added here —
  whether/how a customer erasure (FR-18) interacts with that customer's
  `payment_mandates` rows (which carry `accepted_ip`, arguably personal
  data) is not specified anywhere in `04-data-model.md`. Writing a test for
  this would mean inventing the interaction rather than testing a decided
  one — left as a proposal for `04` to resolve first, not invented here.
  See `12-session-handoff.md`.

## Amendment (Session 5, 2026-08-24) — three open questions settled by ruling

- **Test framework: Pest** (D-0016). This document stays framework-agnostic
  in how it describes layers and cases — naming the framework doesn't change
  any of that — but the choice itself is no longer open.
- **Tenant-isolation suite blocking the fast gate is settled, not awaiting
  confirmation** (D-0017), with an explicit runtime budget (under 60 seconds)
  and a stated response for what happens if that budget is exceeded. See the
  CI integration section below, updated in place, and D-0017 for the full
  reasoning.
- **The single J1 E2E smoke test is settled as the entire MVP E2E scope**
  (D-0018) — no longer "a recommendation, not a settled decision."

This supersedes Session 0/1's deferred stub. No application code exists yet
— everything below is expressed as test *cases* and *layers*, not test
files, PHPUnit/Pest scaffolding, or application code, per this session's
explicit constraint. It is grounded in `02` (actors, journeys, FR/NFR),
`03`/`09` D-0009 (RLS tenant-context lifecycle), `04` (schema, including this
session's D-0008 buffer/occupancy-range and D-0010 mandate-evidence
amendments), `05` (API contract sketch), and `06` (threat model). Where a
question genuinely can't be answered without a real pilot or a real ruling,
it's recorded as open below rather than invented.

## Test layers and what each owns

| Layer | Verifies | Deliberately does NOT test | Talks to real Postgres? | Talks to real Stripe? |
|---|---|---|---|---|
| **Unit** | Pure logic with no I/O: deposit-amount calculation (fixed vs. percentage/bps), buffer/occupancy-range arithmetic, minor-unit money handling, mandate-text templating, wall-clock+IANA-zone → absolute-instant conversion | Anything touching the DB, HTTP layer, or Stripe | No | No |
| **Feature/integration** | API endpoint behavior end-to-end against a real request/response cycle: validation, status transitions, authorization via a real HTTP roundtrip (not a bypassed auth shortcut), response shapes matching `05` | Pure calculation already proven at the unit layer; exhaustive constraint boundary cases (that's the DB-constraint layer's job — a feature test exercises the *happy* and *one* conflict path of J3, not every boundary) | Yes | No (faked client — see Payment flows) |
| **Database-constraint** | The EXCLUDE constraint (D-0007/D-0008), RLS policies (D-0005/D-0009), CHECK constraints, and generated columns *directly* — the places where an ORM abstraction could hide a real difference from what's actually enforced at the DB layer | Application-level business rules already covered elsewhere | Yes, and via genuinely separate connections for true-concurrency cases (see below) | No |
| **Contract** | Endpoint response shape/schema conformance to `05`; Stripe webhook payload shape assumptions against Stripe's published fixture payloads | Business logic — purely shape/schema conformance | No (schema assertions, not full requests) | No (fixture payloads only) |
| **End-to-end (browser)** | The one golden path the business can't ship without: J1 (book + pay a deposit) through the real Nuxt frontend against the real API | Failure paths, edge cases, admin/staff flows — all cheaper and more reliable at the feature layer | Yes | No |

**Expected ratio, and why:** by test *count*, feature/integration should
dominate (roughly half), unit next (roughly a third), with DB-constraint,
contract, and E2E each a small minority. By *runtime*, feature and
DB-constraint tests dominate regardless of count, since both need a real
Postgres instance. This is a solo-track private repo where suite runtime is
a direct, recurring cost to the one person running it on every commit — E2E
stays a deliberately tiny smoke layer (slow, flaky, high maintenance per
test) rather than a coverage layer, and nothing above the unit layer is
allowed to depend on real network calls to Stripe by default (see Payment
flows for the narrower, slower exception).

## Tenant isolation suite

Per `01`'s Definition of MVP-complete and `06`'s threat model, this is the
highest-value suite in the project and is structured as its own named,
complete gate — not folded into general feature tests — so "did the tenant
isolation suite pass" is a single, unambiguous CI signal.

**Mechanism for "how new tables are prevented from shipping without a
policy":** a small manifest (a list, not prose — could be a plain
config/fixture file) of every tenant-scoped table, maintained alongside the
migrations. Two automated checks keep it honest, run against every table in
the actual schema, not hand-written per table:
1. **Manifest-completeness check:** introspect `information_schema.columns`
   for every table with a `tenant_id` column; assert it's in the manifest.
   A new tenant-scoped table added via migration but not added to the
   manifest fails this check — this is what actually stops a new table from
   silently shipping unprotected, not a checklist or code-review memory.
2. **RLS-enforcement check (the "fails loudly if RLS is ever disabled"
   case):** for every table *in* the manifest, query Postgres's own catalog
   (`pg_class.relrowsecurity`, `relforcerowsecurity`) and assert both are
   true, and assert a policy matching the standard tenant-isolation pattern
   exists on it. A table with RLS silently disabled, or force-RLS turned
   off, fails this check regardless of whether any other test happens to
   exercise that table.

Both checks are parameterized over the real schema, so they automatically
cover a table added next month without anyone remembering to write a new
test for it.

**Concrete cases:**

- **Global scope bypassed:** for every table in the manifest — create rows
  for tenant A and tenant B, set tenant context to A via the real
  request-lifecycle mechanism (D-0009), then issue a raw `DB::statement`,
  `DB::select`, and a `DB::table(...)` query builder call (i.e., paths that
  never touch the Eloquent model or its global scope at all) with no
  explicit `tenant_id` filter — assert only tenant A's rows return in every
  case. This is the direct test of D-0005's core claim: RLS holds even when
  the application-layer scope is bypassed entirely, deliberately or by a
  bug.
- **Queue jobs dispatched without tenant context:** attempt to dispatch a
  job that doesn't extend `TenantScopedJob` (or omits `tenant_id`) against a
  tenant-scoped table — assert dispatch-time failure (per D-0009, the
  constructor should refuse this), not silent success with no context set.
- **Jobs retried after a context change:** dispatch a job for tenant A, let
  it fail, dispatch an unrelated job for tenant B to the same worker, then
  let A's job retry — assert the retried run still observes tenant A's
  context (not B's, not stale), verified by asserting the actual GUC value
  visible inside the job handler on the retried run.
- **Cross-tenant ID guessing on every endpoint in `05` that takes a resource
  ID:** a single parameterized test list, generated directly from `05`'s
  endpoint table (appointment, service, staff, customer, payment, refund
  IDs) — for each, authenticate as tenant A's owner/staff, request the
  endpoint with a real ID belonging to tenant B, assert `403`/`404` (never
  `200`, never a `500`, never tenant B's data in the body). Deriving this
  list mechanically from `05` means a newly added `:id`-taking endpoint that
  isn't added to the list is itself a gap worth flagging in review — not a
  guarantee this session can enforce automatically without `05` also
  carrying a machine-readable endpoint manifest, which is future work.
- **Eager loading and relationship traversal:** load an appointment with
  `with('service', 'customer', 'staff')` and assert the loaded relations are
  still RLS-filtered — i.e., a relationship definition can't be used to pull
  a related row belonging to a different tenant even if a future change to
  that relationship's query forgets to interact correctly with the global
  scope, because RLS is the backstop regardless.
- **The `BYPASSRLS` role is not reachable from application runtime:** a test
  that inspects the actual DB credentials configured for the app's runtime
  connection and asserts they don't match `bookslot_migrator`; and a live
  check — connect using the app's configured runtime credentials and assert
  `SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user` is
  `false`.
- **Platform-admin impersonation path (D-0009):** a `platform_admin`-
  authenticated request to the admin endpoint for tenant X returns only
  tenant X's data, never tenant Y's; a non-`platform_admin` user hitting the
  same endpoint gets `403` before any impersonation logic engages.
- **Unauthenticated public-path spoofing:** a public booking request that
  includes a `tenant_id` field in its body (in addition to the `{slug}` path
  parameter) is served against the slug-resolved tenant, with the
  body-supplied value ignored entirely — verifies the G2 resolution's claim
  that customer-controlled input never reaches the GUC.
- **Confirm-payment token-derived tenant context (added Session 7, D-0021):**
  - Fails closed without a valid token: a missing, malformed, or
    signature-tampered `payment_confirmation_token`, and a structurally
    valid token whose `purpose` claim isn't `confirm_payment` (e.g., a
    `manage_token` presented here) — each must return
    `404 INVALID_OR_EXPIRED_TOKEN`, with an identical response shape across
    all these cases (no distinguishing "bad signature" from "wrong purpose"
    from "no such appointment" — there is no separate raw ID in the request
    to leak an existence signal against).
  - A token from one booking cannot confirm another: mint a valid,
    correctly-signed token for tenant A's appointment and assert there is
    no request field that can make it act on tenant B's (or tenant A's own
    different) appointment — the token's embedded `appointment_id`/
    `tenant_id` are the only source of truth for what gets read/mutated.
  - Expired token: a token whose own `expires_at` has passed — independent
    of whether the appointment's hold-window job has actually run yet — is
    rejected the same way as a bad signature, proving the token's expiry is
    a real, independent check, not merely inferred from the appointment's
    live `status`.
  - Retry/double-submit: the same valid token presented twice while
    `status = pending_payment` succeeds both times with identical side
    effects (no duplicate PaymentIntent confirmation beyond what Stripe's
    own idempotency already handles); presented again once `confirmed`, it
    returns an idempotent status echo, never a new mutation or a duplicate
    confirmation email; presented again once `cancelled` (hold expired), it
    returns `409 BOOKING_EXPIRED`, never a 500 and never a silent success.

## Concurrency and slot integrity

**Testing-infrastructure note (read before writing these):** the standard
Laravel test pattern wraps each test in a transaction and rolls it back at
the end (`RefreshDatabase`/`DatabaseTransactions`). That pattern is *wrong*
for true-concurrency tests, because a second, genuinely separate connection
needs something actually committed to conflict with — a rolled-back-at-the-
end single transaction never becomes visible to another connection. The
DB-constraint layer's concurrency cases must open two real, separate
connections, commit deliberately, and clean up afterward with an explicit
`DELETE`/`TRUNCATE`, not rely on the ambient test-wrapper rollback. This is
also why these cases sit in a distinct slower tier (see CI integration).

**Cases:**

- **Two simultaneous booking attempts on the exact same slot:** open two
  concurrent transactions on separate connections, both attempt to insert
  overlapping `appointment_range`s for the same `tenant_id`/`staff_id`;
  assert exactly one commits and the other raises `23P01`, and — one layer
  up, at the feature layer — that the API translates the losing request into
  `409 SLOT_ALREADY_BOOKED`, never a `500` (J3/D-0007). **Built and passing,
  Session 10 (D-0030)** — `tests/Feature/Api/BookingConcurrencyTest.php`.
  A single PHP test process can't produce two genuinely separate database
  connections racing each other (`RefreshDatabase`'s own transaction is
  invisible to a second connection regardless of how it's driven), so this
  goes one level further than "two connections": it spawns two real,
  separate OS processes (`tests/Support/concurrency/probe.php`), each
  bootstrapping its own Laravel application and connection, each
  dispatching one real HTTP-level request through the actual
  router/middleware/`BookingController` stack, synchronized to a shared
  target microtime. Proves the API-layer translation (one `201`, one clean
  `409`) directly, not just the underlying constraint — verified stable
  across repeated runs, not a one-off pass. Fixture data is created and
  committed by a third standalone process (`setup.php`) for the same
  connection-visibility reason. **A genuine finding from running it, not
  anticipated in advance:** under a sufficiently tight race, Postgres's own
  exclusion-constraint check can make the two concurrent inserts
  **deadlock** against each other (`40P01`) rather than one cleanly
  blocking and then failing with `23P01` — `BookingController`'s catch
  only handled `23P01` at first; this real two-process test caught the gap
  within a handful of runs (a mocked or single-connection test never
  would have). Both SQLSTATEs now map to the same `409 SLOT_ALREADY_BOOKED`
  — see `09-decision-log.md` D-0030.
- **`'[)'` boundary case:** a booking ending exactly at 3:00pm and another
  starting exactly at 3:00pm for the same staff, zero buffer — both must
  succeed (half-open bounds means they don't overlap).
- **Boundary case with buffer (D-0008):** a booking ending at 3:00pm with
  `buffer_after_minutes = 15`, and a second booking starting at 3:05pm for
  the same staff — must conflict (`occupancy_range`s overlap) even though
  the literal `appointment_range`s don't. The mirror case: a second booking
  starting at 3:15pm or later — must succeed, since the buffered window has
  fully elapsed by then. This is the direct regression test for the G1 race
  D-0008 exists to close.
- **DST-crossing appointments:** pin literal fixture dates for the
  spring-forward and fall-back transitions in a DST-observing tenant
  timezone (e.g., specific March and November dates for `America/Toronto`),
  not computed at test-run time. Verify a working-hours rule ("9am–5pm")
  applied on the transition date produces the correct absolute `tstzrange`
  (the wall-clock duration and the UTC-instant duration legitimately differ
  by an hour on those dates), and that the exclusion constraint's overlap
  check remains correct across the boundary since it operates on absolute
  instants regardless.
- **Cancelled/soft-deleted rows are rebookable:** cancel an appointment
  (`status → cancelled`), then verify the identical slot (same literal range
  and same buffered range) can be booked again — exercises the partial
  `WHERE` clause on both the pre-D-0008 overlap semantics and the new
  `occupancy_range` path.
- **Buffer enforcement under race:** the literal G1 scenario reproduced —
  two concurrent requests each compute available slots via the real
  availability-read logic, each sees the other's slot as open (because
  neither has committed yet), both attempt to book adjacent slots that
  satisfy buffer individually but would violate it together; assert one
  succeeds and one gets `409`, never both `201`.
- **The D-0008 invariant, stated directly (added Session 6 — the actual
  property D-0008 exists to provide, not merely adjacent behavior):**
  create a service with a known buffer configuration, book an appointment
  against it (the row snapshots that buffer into its own
  `buffer_before_minutes`/`buffer_after_minutes`), then change the
  *service's* buffer configuration to a different value. Assert two things
  in the same test: (1) the existing appointment's `occupancy_range` (and
  its underlying `buffer_before_minutes`/`buffer_after_minutes`) is
  byte-for-byte unchanged after the service's config changed — nothing
  about updating `services.buffer_before_minutes`/`buffer_after_minutes`
  may retroactively alter a row that already snapshotted the old value; and
  (2) a *new* booking created against the same service after the change
  snapshots the *new* buffer value, not the old one. This is the direct
  regression test for the exact gap D-0012's postscript in
  `09-decision-log.md` describes: every prior test asked whether the schema
  was internally consistent, none asked whether this specific guarantee
  actually held.

**Amendment (Session 4, 2026-08-24) — cases added after D-0008's DDL was
corrected by execution testing (see `09-decision-log.md`'s D-0008
amendment for the full findings these cases regress-test):**

- **Recompute-and-compare integrity check:** for every row in
  `appointments`, call `occupancy_window(appointment_range,
  buffer_before_minutes, buffer_after_minutes)` fresh and assert the result
  equals the row's stored `occupancy_range` exactly. This is not a
  hypothetical — it is the direct check for whether the generated column
  and the function it calls have silently drifted apart (e.g., a future
  `CREATE OR REPLACE FUNCTION occupancy_window` that changes behavior
  without a corresponding column rebuild — see the post-restore case
  below for exactly this scenario materializing).
- **Buffer rejection tests:** `NULL` for either buffer column is already
  foreclosed by `NOT NULL` and needs no test beyond a schema-level
  assertion that the columns remain `NOT NULL`. Below-zero and
  above-ceiling values must each have a dedicated rejection test — not
  merged into one "invalid buffer" case, because they are different bugs
  with different consequences:
  - **`buffer_before_minutes = -5` (or any negative value) specifically:**
    this is the case execution testing showed does *not* fail loudly by
    itself — a negative, non-inverting buffer silently produces an
    `occupancy_range` **narrower** than `appointment_range` rather than
    erroring, which is precisely why the containment CHECK
    (`occupancy_range @> appointment_range`) exists as a real invariant
    and not a redundant belt-and-suspenders check. The test must assert
    the insert is rejected by that CHECK specifically (assert on the
    constraint name, not just "some error"), since a regression that
    silently drops or weakens the containment CHECK would otherwise pass
    this test for the wrong reason (the buffer-bounds CHECK alone still
    catching it).
  - **An absurd buffer (e.g. 10 years / more than 1440 minutes)
    specifically:** execution testing showed this also silently succeeds
    without the ceiling CHECK — assert rejection by the `<= 1440` bound.
  - Both cases together are the concrete regression test for "buffer
    bounds are load-bearing, not defensive filler," per `04`'s redundancy
    note on the containment CHECK.
- **`search_path` shadowing:** create a hostile schema containing a
  same-named, same-signature shadow of a function `occupancy_window`
  calls internally (e.g. `make_interval`) that returns an obviously wrong
  result; set the test session's `search_path` to place that schema before
  `pg_catalog`; call `occupancy_window` (or trigger it via an insert) and
  assert the correct, unhijacked result. **The variant that actually
  reproduces the attack, and the one this test must use:** Postgres
  implicitly searches `pg_catalog` first unless the session's `search_path`
  explicitly repositions it — so the test must set
  `search_path = hostile_schema, public, pg_catalog` (`pg_catalog` present
  but repositioned), not merely `search_path = hostile_schema, public`
  (which wouldn't reproduce the vulnerability at all, since `pg_catalog`
  would still be implicitly searched first regardless). A test using the
  second, non-repositioning form would pass even against an unhardened
  function and give false confidence.
- **Post-restore verification.** This is a **disaster-recovery risk, not
  only an integrity one, stated at full strength:** execution testing
  showed that `STORED` generated columns are excluded from `pg_dump`'s
  data output and are recomputed from their expression at restore time —
  and when the target's `occupancy_window` definition differed from what
  produced the original dump, `pg_restore` **aborted** on an exclusion
  violation (the recomputed `occupancy_range` values no longer satisfied
  `no_overlapping_appointments` against each other). Concretely: **function
  drift between dump and restore can render a backup unrestorable.**
  Scoped honestly — this is not every restore path:
  - **Safe:** a plain restore of a matched dump (schema + data from the
    same `pg_dump`) into a fresh, empty database — the function definition
    travels with the schema portion of the same dump, so there is no
    drift to hit.
  - **Exposed:** a data-only restore against a database whose schema (and
    therefore its `occupancy_window` definition) was migrated
    independently; a restore into a pre-existing schema that already
    diverged from the dump's schema; and point-in-time recovery replayed
    across a migration that changed the function mid-stream.
  - The recompute-and-compare check above is exactly the right tool for
    this: run it as a standard step immediately after any restore that
    isn't a from-scratch matched-dump restore. Forward pointer: once
    `08-deployment-and-operations.md` exists and defines this project's
    actual backup/restore procedure, this check belongs there as a named,
    standard post-restore step — not left as a fact recorded only here.

**Is SQLite usable here? No — stated plainly, not softened.** SQLite has no
`tstzrange` type, no GIST index or `EXCLUDE USING gist` support, and no Row-
Level Security at all. None of D-0005/D-0007/D-0008's mechanisms can be
expressed in SQLite, so this entire layer requires a real Postgres instance
(local, containerized, or a CI service container) — there is no faster
SQLite-backed substitute for any test in this section. More broadly: this
project shouldn't maintain a parallel SQLite-compatible path anywhere in the
suite just to get nominally faster tests, because a passing SQLite-backed
test would hide exactly the correctness properties (exclusion constraints,
RLS) the project cares most about. Every DB-touching tier in this document
assumes real Postgres, full stop.

## Payment flows

**What's faked vs. what's real, by default:**

- **Unit/feature layer (the default, every commit):** a faked Stripe client
  — a hand-rolled fake implementing the exact PaymentIntent/Refund/webhook-
  event interfaces the app actually calls, not a real network call. No real
  or test-mode Stripe account needed. Covers: request/response shape
  handling, error-code branching (`card_declined`,
  `authentication_required`), idempotency-key usage, and the app's own state
  transitions (`payments`/`appointments` status changes). **First concrete
  instance, Session 10 (D-0030):** `App\Payments\PaymentIntentGateway`
  (interface) / `tests/Support/FakePaymentIntentGateway.php` (the fake,
  configurable to succeed, delay, or throw) / `StripePaymentIntentGateway`
  (the real stripe-php implementation, wired as the default binding but not
  yet exercised against real Stripe — see this file's Session 10 amendment
  above).
- **A narrower, slower Stripe-test-mode subtier (nightly/pre-deploy, not
  every commit):** real API calls against a real Stripe *test-mode* Connect
  account, using Stripe's documented test card numbers for specific decline
  and 3DS/SCA scenarios. This exists because the faked client can only be as
  correct as its own shape assumptions — it can't catch "we're calling the
  real Stripe API wrong" (a bad field name, a wrong Connect
  destination-charge parameter, an API-version mismatch). Slower and
  dependent on Stripe's own test-mode availability, so it doesn't belong in
  the fast gate.
- **Webhook signature verification:** doesn't need real Stripe test mode —
  Stripe's SDK provides a helper to construct a validly-signed test payload
  using a known signing secret. Assert a valid signature is accepted and a
  tampered payload or wrong secret is rejected *before* any business logic
  runs (FR-13/06).
- **Idempotency and duplicate delivery:** deliver the same webhook event ID
  twice; assert the second delivery is a no-op end to end — the
  `stripe_webhook_events` unique constraint prevents double-insert, and no
  downstream side effect (double-confirmation, duplicate confirmation email)
  occurs (J9/NFR-04).
- **Out-of-order webhook arrival:** simulate the synchronous-confirmation
  path completing first (appointment already `confirmed`) with the webhook
  for the same PaymentIntent arriving after — assert it's a no-op beyond
  recording the event. Also the reverse (webhook arrives before any
  synchronous confirmation, e.g. after a 3DS redirect) — assert it performs
  the confirmation exactly once.
- **The off-session balance charge, including SCA decline (J5):** Stripe's
  documented test-mode payment methods that specifically trigger
  `authentication_required`/off-session decline behavior; assert the app's
  handling matches FR-10 exactly (a `200` with `status: failed` and
  `fallback_action: mark_paid_manually`, per `05`'s endpoint 6 — never a
  silent failure).
- **The balance charge reuses the mandate's saved payment method (added
  Session 6 — the actual D-0006/D-0010 guarantee, not just the decline
  handling above):** trigger `POST /api/owner/appointments/{id}/balance/charge`
  and assert the faked Stripe client is invoked with exactly the
  `stripe_payment_method_id` recorded on that appointment's
  `payment_mandates` row — not a different or newly-collected payment
  method. This is the direct test that D-0006's "off-session" model and
  D-0010's mandate evidence are actually wired to the same charge attempt,
  rather than two decisions that happen to describe a consistent story
  without anything enforcing the link between them.
- **The D-0010 erasure test, now writable given D-0022 (added Session 7):**
  create a customer, a booking, and its `payment_mandates` row (including
  `accepted_ip`/`accepted_user_agent`); action FR-18 erasure on the
  customer; assert `customers.name`/`email`/`phone` are nulled
  (`erasure_requested_at` set) exactly as `04`'s existing erasure handling
  already specifies, **and**, in the same test, assert every column on the
  linked `payment_mandates` row — including `accepted_ip` and
  `accepted_user_agent` specifically, per D-0022's classification of both
  as evidentiary rather than identifying — is byte-for-byte unchanged.
  Assert the mandate remains linkable via `appointment_id` to its
  `booking_events` audit trail (the `mandate_accepted` event and its
  `metadata` reference), so a dispute could still be reconstructed from
  `booking_events` + `payment_mandates` + `stripe_webhook_events` together,
  unaffected by the customer's erasure.
- **Refunds:** full and partial refund flows against a captured deposit,
  including the business rule that a refund can't exceed the remaining
  refundable balance — mostly faked-client feature tests, with one real
  test-mode refund in the slow tier.
- **Disputes:** `charge.dispute.created`/`closed` webhook handling — assert
  a `booking_events` audit entry is created and nothing else auto-mutates
  (per `05`, disputes are tracked, not auto-resolved). Built and tested
  Session 22 (D-0053, `tests/Feature/Api/StripeWebhookControllerTest.php`):
  correct tenant resolution via the Dispute's own `payment_intent` field,
  proven across two tenants so only the correct one is touched; the
  orphaned/edge-case failure path (a `payment_intent` matching no known
  payment in any tenant, and a dispute carrying no `payment_intent` at
  all) both leave the event genuinely unprocessed rather than silently
  misattributed.

**What genuinely cannot be tested automatically — a manual pre-launch
checklist, not a gap in the automated suite:**
- The full Stripe Connect Express hosted-onboarding UX with a real identity
  — test-mode onboarding exists but doesn't reproduce real identity-
  verification friction.
- Actual deliverability of reminder emails/SMS avoiding spam filters — an
  inherently production/real-inbox observation (and per `06`'s "what must
  remain private forever," the tuning itself is never documented here even
  once observed).
- A real dispute's full lifecycle and Stripe's actual evidence-submission
  requirements — partially simulable via webhooks, but the response process
  itself is manual.
- Real bank payout timing and behavior on a live Connect account.

This list is the manual pre-launch checklist `01`'s Definition of
MVP-complete implicitly calls for ("demonstrably working end-to-end against
a real Stripe test-mode Connect account, not merely coded") — it should be
walked through by hand against a real Stripe test-mode Connect account
before any real pilot goes live, and is a natural candidate to live
alongside `08-deployment-and-operations.md` once that file is written.

## Notification coverage (added Session 7)

No dedicated `notification_deliveries` test section existed before this
session; this is its first concrete case, seeded by D-0023's new
`rebooking_invite` purpose value:

- **Manual re-invite records a distinct purpose:** trigger
  `POST /api/owner/customers/{id}/re-invite` (FR-23) for a customer whose
  most recent appointment was a `no_show` — assert it succeeds (FR-23 is
  explicit this isn't gated on no-show status) and creates a
  `notification_deliveries` row with `purpose = 'rebooking_invite'`,
  `channel = 'email'`, sent immediately rather than scheduled. In the same
  test, assert a `completed` appointment's automatic fire-once prompt (J10)
  is recorded as `purpose = 'rebooking_prompt'` — the two values must never
  be conflated, since D-0014's entire point was keeping "manual, owner
  judgment" distinguishable from "automatic, system-triggered" in the data
  itself, not merely in product behavior.

## Time and timezone

- **Clock control:** every time-dependent test pins a specific instant
  (e.g. `Carbon::setTestNow()`) rather than asserting relative-to-real-now
  behavior — required for hold-window expiry (J2), reminder scheduling
  (FR-06), and the rebooking-prompt fire-once timing (J10) to be
  deterministic and reproducible regardless of when the suite actually runs.
- **Studio-timezone fixtures:** maintain a small, fixed set of named tenant
  fixtures spanning more than one IANA zone — at minimum one DST-observing
  zone (e.g. `America/Toronto`) and one fixed-offset zone — so
  timezone-dependent logic is exercised against more than whichever zone the
  developer happens to be sitting in.
- **DST transition dates to pin:** the actual, published spring-forward and
  fall-back dates for the chosen zone(s) in the relevant test year(s),
  hard-coded as literal fixture dates — never computed at test-run time,
  since the whole point is a deterministic, specific calendar date.
- **Multi-week lead times (D-0006):** include at least one fixture
  appointment booked several weeks out, to exercise the full reminder
  cadence (7d/24h/2h per FR-06) and confirm nothing in the booking or
  reminder-scheduling path implicitly assumes a short lead time — this is
  the concrete scenario D-0006 disqualified manual-capture holds over, so
  it's worth a standing regression fixture, not just a one-off check.

## Test data

- **Factory strategy under multi-tenancy:** every factory that produces a
  tenant-scoped row accepts an optional explicit tenant and otherwise
  lazily creates its own — never defaults to a shared "well-known" tenant ID
  reused across the suite. This means an individual test that doesn't care
  about tenancy doesn't have to wire a tenant by hand, but a test that
  *does* care (most of the tenant-isolation suite) always gets two genuinely
  distinct, freshly created tenants rather than incidentally sharing one.
- **Avoiding tenant-leaking fixtures:** a shared global "the test tenant"
  fixture is exactly the kind of setup that would mask a cross-tenant bug —
  if tenant A's and tenant B's test data accidentally shared a factory
  default, an isolation bug could pass invisibly. Convention: any test
  exercising isolation constructs both tenants inline, explicitly, in that
  test.
- **No real customer data**, unchanged from Session 0/1's stub and this
  developer's practice elsewhere — purely synthetic fixtures throughout.

## CI integration

This repo gates merges on `composer ci:check`. Split by cost and blocking
value:

- **In the fast gate (every commit/PR), target under ~5 minutes total:**
  static analysis, coding style, all unit tests, the feature/integration
  tests that fit inside a single rolled-back-transaction test wrapper
  against a real (containerized) Postgres instance, and — **settled, not a
  recommendation awaiting confirmation (D-0017)** — the full tenant-isolation
  suite, carrying its own **runtime budget of under 60 seconds** within that
  overall 5-minute target. That suite blocking merge, not just running
  nightly, is the direct consequence of `01`'s Definition of MVP-complete
  calling tenant isolation the one correctness property this product cannot
  ship without; catching a regression the morning after it merged is too
  late for this specific property. **If the suite's runtime grows past its
  budget:** optimize first (a shared templated test database, parallelized
  per-table catalog checks); shard/parallelize the suite's own cases within
  the fast gate if that isn't enough; only as a last resort, move specific
  slow-but-non-core cases to the nightly tier — never the manifest-
  completeness or RLS-enforcement catalog checks themselves, which are what
  make the suite trustworthy against a new, accidentally-unprotected table.
  A blocking suite that silently grows past its budget doesn't fail safely —
  it gets bypassed by whoever is shipping under time pressure, which is a
  social failure mode a stated numeric budget exists to make visible before
  it happens. See D-0017.
- **In a slower nightly/pre-deploy tier:** the true-concurrency DB-
  constraint tests (separate-connection, real-commit tests that can't use
  the rolled-back wrapper), the real-Stripe-test-mode payment subtier, and
  the small E2E browser suite. These are excluded from the fast gate because
  they're either genuinely slow, occasionally flaky by nature (real
  concurrency, a real external system), or dependent on Stripe's test-mode
  availability — none of which should block a solo developer's normal
  commit cadence.

## Coverage philosophy

No blanket coverage-percentage target, and no percentage is fabricated here
to look rigorous — a percentage gate tends to reward tests written to move
the number rather than to catch real bugs. Instead, these specific paths
are named as required, and their absence is a blocking gap regardless of
whatever the overall number says:

- Every transition in both state machines in `04` (booking lifecycle,
  per-row payment status) has at least one test per edge — including edges
  that must be *rejected* (e.g. `pending_payment → completed` directly),
  not merely left untested.
- The tenant-isolation suite covers 100% of tenant-scoped tables via the
  manifest mechanism above, with no manually-maintained exception list.
- Every MVP-marked FR/NFR in `02` traces to at least one test — referencing
  the FR/NFR ID in the test name/description so this is auditable by a
  simple search, not asserted from memory.
- Every documented error case in `05` (`409 SLOT_ALREADY_BOOKED`,
  `409 BOOKING_EXPIRED`, `409 INVALID_STATUS_TRANSITION`, cross-tenant
  `403`, etc.) has a test that produces that exact error.
- A raw line/branch coverage number can still be measured and watched for a
  sharp, unexplained drop (a signal something got skipped), without being a
  merge gate itself.

## Open questions

**Needs a pilot to answer:**
- What level of simulated concurrency is worth testing beyond the
  correctness-proving minimum of two simultaneous requests (e.g., whether a
  10-way concurrent burst on one popular slot is a realistic pattern worth a
  dedicated test) — unknown until real booking traffic exists.

**Resolved by ruling, Session 5 — no longer open:**
- E2E scope: the single J1 smoke test is the entire MVP E2E layer — D-0018.
- Tenant-isolation suite blocking the fast gate, with a stated runtime
  budget — D-0017.
- Test framework: Pest — D-0016.

## Amendment (Session 12, 2026-08-26) — confirm-payment's real coverage, still fake-tier only

`PaymentConfirmationControllerTest.php` gained real coverage for what D-0033
built (previously untestable, since the Session-9 stub had no Stripe-facing
behavior to test): a successful confirmation backfilling
`payment_mandates.stripe_payment_method_id` from the gateway's `retrieve()`
response; a synchronous decline reporting `last_payment_error` honestly
while leaving the appointment `pending_payment` and moving `payments.status`
to `failed`; and a J2 retry where the same PaymentIntent's status changes
between two calls. All of it runs against `FakePaymentIntentGateway`
(this section's own "faked Stripe client by default" tier) — **the
real-test-mode subtier this section has named since Session 3 still has no
tests in it**, for confirm-payment or for booking-creation. See
`09-decision-log.md` D-0034 for why (real credentials still not available)
and this file's own "payment-flow testing" section above for what that
subtier was always meant to cover once they are.

One test-infrastructure detail worth carrying forward for whoever writes the
real-test-mode subtier: `FakePaymentIntentGateway::$retrieveStatus` now
accepts an ordered list of statuses, consumed one per call on a single fake
instance, instead of requiring two separate container bindings — Laravel's
`Route` object caches a resolved controller for the route's lifetime, so a
mid-test container rebind between two requests to the *same* route doesn't
reach an already-constructed controller. Full reasoning:
`09-decision-log.md` D-0033.

## Amendment (Session 17 follow-up, 2026-08-26) — a real cross-process re-auth test category, closing a gap D-0043 itself left open

A direct question after D-0043 landed: does anything in this suite now catch
a *regression* of that bug class? Checked, not assumed — it did not.
`SequentialRequestTenantContextTest.php` (line 56 above) and every
login-then-request test in `AuthControllerTest.php`/
`OwnerAppointmentControllerTest.php`/`StaffAppointmentControllerTest.php`
all run inside one Pest test method on one shared in-process connection —
exactly the condition D-0043's own writeup names as what masked it for six
sessions. Proven empirically, not just argued: with D-0043's fix temporarily
reverted, `OwnerAppointmentControllerTest.php` stayed **8/8 green**, while
the new test below correctly failed.

**New category: `tests/Feature/Api/SequentialProcessReauthTest.php`**,
following `BookingConcurrencyTest.php`'s existing pattern (real, separate OS
processes — `tests/Support/reauth/`) but for a *sequential* re-auth
scenario rather than a simultaneous race: process 1 creates and commits a
tenant/owner; process 2 performs a real login and captures the
post-`session()->regenerate()` cookies; process 3 is a **brand-new process
with its own, never-before-touched database connection** presenting only
those cookies — reproducing the live-`curl` condition that actually found
D-0043, at the connection-freshness level a shared-connection Pest test
structurally cannot reach.

**A second gap found building it:** `.env.testing`'s `SESSION_DRIVER=array`
(deliberate for every other test — see its own comment) doesn't survive a
process boundary, and forcing `SESSION_DRIVER=file` via a bare `putenv()`
in the child silently didn't take effect specifically when spawned as
Pest's own child (Pest's parent process had already populated
`$_SERVER`/`$_ENV`, which a child inherits and which Dotenv reads ahead of
`getenv()`). Fixed in `tests/Support/reauth/bootstrap.php` by setting
`putenv()`/`$_ENV`/`$_SERVER` together. Full account: `09-decision-log.md`
D-0044.

This is a distinct test *category*, not a one-off: any future auth/tenancy
decision whose correctness depends on "the first query a connection ever
runs" (D-0043's exact failure shape) belongs here, in a genuinely separate
process, not as another sequential Pest assertion.
