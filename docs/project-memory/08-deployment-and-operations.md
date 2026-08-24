# Deployment and Operations
> Purpose: how this runs, and how someone else keeps it running.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-24 (Session 5 — first full version, superseding Session 0/1's deferred stub; amended Session 8 — `composer ci:check` now exists, verified exit 0, real contents recorded against this document's prediction)

This is the first real pass at this file. No application code exists yet, so
everything below is an operational design decided against `03`
(architecture), `04` (schema, including D-0008/D-0009's buffer/RLS
mechanisms), `06` (threat model), `07` (testing strategy, including the
recompute-and-compare integrity check and manual pre-launch checklist this
file points at rather than duplicates), and `09`'s decision log (D-0009,
D-0011–D-0020) — not invented independently of them. Where a choice
genuinely cannot be made without real hosting budget or real pilot traffic,
it is named as an open question at the end rather than answered with an
invented number.

## PostgreSQL version and provider

**Target version: PostgreSQL 17.** `04-data-model.md`'s D-0008 amendment
already pins a hard **floor** of PostgreSQL 12 (required by `GENERATED
ALWAYS AS (...) STORED`) and notes that floor is non-binding in practice
since PostgreSQL 12 is already end-of-life. This document owns the separate
question of what actually gets deployed, above that floor:

- PostgreSQL 17 was released in 2024 and is supported (per the PostgreSQL
  project's standard five-year major-version support window) into November
  2030 — roughly four years of runway from today, not a version about to
  need a forced upgrade.
- It is deliberately **not** the newest major version (PostgreSQL 18 is
  already out) — picking the newest available version the day it ships
  would be optimizing for novelty, not lifecycle, which is exactly the
  wrong instinct for a price-sensitive product whose operator has no
  bandwidth to chase major-version edge cases. PostgreSQL 17 has had time to
  settle.
- It is **not** the oldest still-supported version either — PostgreSQL 14
  is scheduled to go end-of-life in November 2026, a few months from this
  document's writing, which would make it an irresponsible pin for a system
  not yet in production.
- Concretely, it also matches the exact version (`17.11`) this project's own
  D-0008 execution testing (`09-decision-log.md`'s D-0008 amendment) was
  already run against — deploying on the same major version means that
  testing carries forward directly, instead of needing to be re-validated
  against whatever version actually gets deployed.

**`btree_gist` availability — checked, not assumed, against three candidate
managed providers:**

| Provider | `btree_gist` installable without superuser? | Notes |
|---|---|---|
| **AWS RDS for PostgreSQL** | Yes | Listed among RDS's supported extensions installable by a role with plain `CREATE` privilege — does not require the `rds_superuser` role. |
| **Supabase** | Yes | Pre-installable/enableable via the project's Database → Extensions dashboard; a standard, listed extension, not a special request. |
| **Neon** | Yes | Documented as a supported extension in Neon's own extension reference. |

(Google Cloud SQL for PostgreSQL was also checked and also supports
`btree_gist`, version-pinned per Postgres major version, as a fourth data
point — not treated as a fourth equally-weighted candidate here only because
this document doesn't otherwise compare GCP's pricing/ops model below.)

**The general caution stands regardless of which of these is eventually
chosen:** some managed Postgres offerings restrict `CREATE EXTENSION` or any
superuser-adjacent operation to an allowlist maintained by the provider, and
that allowlist can change between when this document was checked and when a
real hosting decision is made — re-verify against the provider's current
extension documentation immediately before committing, not from this table
alone once real time has passed.

**Not decided here — genuinely budget/pilot-dependent:** which specific
provider, which region, and which pricing tier. All three checked candidates
clear the one hard technical requirement (`btree_gist`); choosing between
them is a cost/operational-fit decision this document isn't positioned to
make without a real budget or a real pilot's expected load and geography.

## Database roles and credential placement

Per `09-decision-log.md` D-0009 and D-0020, two roles, stated here in terms
of where each credential actually lives operationally:

- **`bookslot_app`** — the only role the running application authenticates
  as, for both HTTP requests and Horizon queue workers. Ordinary role,
  fully RLS-subject, never granted `BYPASSRLS`. Its credential lives in the
  application's own runtime secrets (the hosting platform's environment/
  secrets store — e.g. injected as an environment variable at container
  start), the same place `STRIPE_SECRET_KEY` and the webhook signing secret
  live. A compromise of this credential is bounded by RLS — it can never see
  or touch data outside whatever tenant context a given request/job sets.
- **`bookslot_migrator`** — holds `BYPASSRLS`, owns the tables, used only
  for migrations, seeders, backfills, and `pg_dump`/`pg_restore`. **Stated
  plainly, per D-0020: this credential is never stored in the CI/CD
  pipeline's general secret store, and migrations do not run automatically
  as part of an ordinary merge-to-`main` deploy.** Instead, a migration is a
  deliberate, separate, manually-triggered step: an operator fetches the
  migrator credential just-in-time from a secrets manager (e.g. the same
  vault used for the operator's other infrastructure credentials) for that
  one invocation — either running it directly from a secured session, or
  triggering a CI job that is itself gated behind a required manual approval
  and is the *only* job in the entire pipeline configuration permitted to
  read that credential. Ordinary build/test/deploy jobs never have access to
  it.
- **The cost of this, restated from D-0020 so it isn't lost in this
  document either:** there is no fully automated merge-to-production
  pipeline for any release that includes a migration. A human must run it,
  in the correct order relative to the application deploy (see Migration
  safety below), which is a real, recurring manual step — and manual steps
  under release-day time pressure are exactly the kind of thing that gets
  skipped or done out of order. This is accepted because the alternative —
  the system's one `BYPASSRLS`-capable credential sitting in general CI
  secrets, reachable by every ordinary pipeline run — trades a contained,
  human-gated risk for a larger, more automatable one.

## Connection pooling

**PgBouncer in transaction mode (or a managed pooler verified compatible
with it) — chosen, and safe specifically because of how it lines up with
D-0009.** D-0009 requires `set_config('app.current_tenant_id', ?, true)` to
be the first statement in an explicit transaction, for every tenant-scoped
request and job, resetting automatically at `COMMIT`/`ROLLBACK`. Transaction-
mode pooling reclaims a backend connection at exactly that same boundary —
the two lifecycles are the same event, not two things that happen to agree
today. This is not a new decision; it's the forward constraint D-0009 and
`03-architecture.md` already named, now the actual chosen operational
posture rather than a note for later.

- **Confirmed as transaction-mode-PgBouncer-compatible, from the provider
  check above:** Supabase's built-in pooler (Supavisor) explicitly supports
  a transaction mode documented as behaving the same as PgBouncer's; Neon's
  built-in pooler runs PgBouncer in transaction mode natively. Either is a
  safe default if that provider is the one eventually chosen.
- **Session-mode pooling also works, but is not preferred:** the GUC still
  resets correctly (its reset is tied to the transaction boundary, not to
  when the pool reclaims the connection), but each app-side connection ties
  up a backend connection for its entire session rather than only its active
  transactions — safe, just wasteful, and gives up the main reason to run a
  pooler at all.
- **What is not compatible, and a specific, checked example of why: AWS RDS
  Proxy.** Independent of whether RDS itself is chosen as the database,
  RDS Proxy's own documented pinning behavior for PostgreSQL is that *any*
  session-variable or configuration-setting change pins that client
  connection to one specific backend connection for the rest of that
  client's session — and this is documented to apply even to
  transaction-scoped `SET`/`SET TRANSACTION`, not only session-level `SET`.
  Since D-0009's entire mechanism is a `set_config(...)` call on every
  single tenant-scoped request and job, putting RDS Proxy in front of this
  database would pin essentially every connection it ever handles. This
  wouldn't corrupt tenant isolation — each pinned connection is still a
  distinct backend connection with its own correctly-set GUC — but it would
  silently defeat the entire purpose of running a pooler (no connection
  multiplexing benefit at all), quietly reopening the connection-limit
  problem pooling exists to solve. **If RDS is chosen as the database, pair
  it with a self-run PgBouncer in transaction mode instead of RDS Proxy —
  do not put RDS Proxy in front of this schema.**

## Backup and restore

**Cadence and retention (MVP default, not budget-blocked):** daily
automated base backups plus continuous WAL archiving for point-in-time
recovery, with a 7-day PITR window and 30 days of retained daily snapshots.
This matches what a managed provider's base tier typically bundles already
(not a number invented in isolation) — extending the PITR/retention window
further is a straightforward cost-scaling knob for a later session once real
pilot data volume and its actual business value are known, not something
blocked on a decision this document needs to make now.

**The recompute-and-compare integrity check (`07-testing-strategy.md`) is a
standard, required step immediately after every restore that isn't a
from-scratch matched-dump restore into an empty database** — this is the
forward pointer `07` explicitly left for this file. Concretely: after any
such restore, before it is trusted, (1) run the recompute-and-compare check
(recomputing `occupancy_window(...)` fresh for every `appointments` row and
asserting it matches the stored `occupancy_range` exactly) and (2) validate
the exclusion constraint (`no_overlapping_appointments`) against the fully
restored table, since `pg_restore`'s own natural abort-on-violation behavior
(observed directly in `07`/`09`'s D-0008 amendment) does not necessarily
apply the same way to a data-only restore performed outside `pg_restore`'s
own schema-plus-data flow — and (3) compare row counts on `payments`,
`refunds`, `booking_events`, and `stripe_webhook_events` against the source,
since these are the append-only/audit tables a partial or corrupted restore
would most damage this business's ability to reconstruct dispute evidence
from.

**The three cases `07` named as exposed, addressed explicitly, not
re-litigated:**
- **Data-only restore against an independently-migrated schema.** Before
  performing one, diff the deployed `occupancy_window` function definition
  (`pg_get_functiondef`) between dump-time and the restore target; if they
  differ, reconcile the function first or treat the restore as unverified
  until the recompute-and-compare check has been run and passes.
- **Restore into a pre-existing schema that has already diverged from the
  dump's schema.** Treated as the risky path, not the default — prefer
  restoring into a fresh, empty database whenever the situation allows it
  (the case `07` calls "safe"); when a pre-existing-schema restore is
  unavoidable, it requires the same function-definition diff plus a full
  recompute-and-compare pass before the restored data is used for anything.
- **Point-in-time recovery replayed across a migration that changed the
  function mid-stream.** A PITR target time should be chosen to land either
  fully before or fully after any migration that touches
  `occupancy_window` (or any other function backing a `STORED` generated
  column) — never mid-migration — and the recompute-and-compare check still
  runs immediately afterward regardless, as a check, not a substitute for
  choosing the target time carefully.

## Environments, deploy, and migration safety

**Topology:** local (developer machine), staging (a persistent
pre-production environment mirroring production configuration, used to
rehearse a migration or deploy before it touches real tenant data),
production. No multi-region topology — matches `01`'s single-country,
single-location-segment scope; nothing here anticipates a scale this product
isn't built for yet.

**Deploy:** application code build and test run automatically in CI on every
push (the fast gate from `07`); a successful build deploys automatically to
staging. Promotion from staging to production is a manual/gated approval
step — appropriate for a product handling real payment data even at pilot
scale, and consistent with keeping the one `BYPASSRLS`-capable credential
(D-0020) out of anything fully automatic.

**Migration safety — expand/contract, not single-step shape changes:** any
migration that removes or renames something the *currently live* application
version still reads or writes must be split into an expand step (add the new
shape; the app is updated to read/write both old and new as needed) that is
deployed and soaked first, followed by a later, separate contract step (drop
the old shape) only once no deployed app version still depends on it. A
migration that changes shape in one step and assumes the old app version
stops running the instant the migration completes is not safe under this
project's deploy model, where the migration (D-0020's manual step) and the
application promotion are two separate actions, not one atomic event.

**What happens when a migration and the running application version
disagree:** both of this schema's core correctness mechanisms — the
transaction-scoped tenant GUC (D-0009) and the exclusion constraint
(D-0007/D-0008) — are enforced by Postgres itself, not by application code
agreeing with itself. A mismatched pairing (old app code against a
newly-migrated schema, or the reverse) fails loudly at the database layer —
a missing column, a constraint the old code doesn't expect — rather than
silently writing wrong data. "Fails loudly" still means real requests error
during any mismatch window, though, which is exactly what the expand/contract
discipline above exists to avoid in the first place, not something to rely
on as an acceptable steady state.

**Rollback — what's reversible, what isn't:** application code is always
reversible (redeploy the prior build/image; this is why keeping app deploys
fast and automatable matters). A completed, data-bearing migration is
**not**, in general, safely reversible once real rows exist under the new
shape (e.g., once real `payment_mandates` or `appointments` rows have been
written referencing new columns or constraints) — a `down()` migration
executed against live data would have to invent an answer for what happens
to that new data, which is a product decision, not a mechanical reversal.
This project's policy: **roll forward, not back**, for any migration that
has already run against real data. A problem discovered after a migration
is fixed by a new, forward migration addressing the actual issue, not by
running that migration's `down()` against production. `down()` migrations
may still exist for local development convenience; they are not a
production incident-response tool.

## Stripe operational surface

**Webhook endpoint exposure and signature verification, per environment:**
one webhook endpoint per environment — a test-mode endpoint for
local/staging pointed at a Stripe test-mode webhook, a live-mode endpoint
for production pointed at a live-mode webhook — each with its **own**
signing secret, stored as an environment-scoped application secret
(reachable by `bookslot_app`'s runtime only, never by CI). Stripe signs
per-endpoint, so a payload valid for one environment's secret cannot
validate against another's by construction; every handler still verifies
`Stripe-Signature` before anything else runs, per `06`/FR-13, in every
environment without exception — there is no "trusted" environment that
skips this.

**Key management and rotation:** the platform's Stripe secret key and each
environment's webhook signing secret live in the hosting platform's secrets
manager, never committed to the repository (per `06`, unchanged). Rotation
runbook: generate the new key in the Stripe dashboard (Stripe supports an
overlap window where both old and new keys remain valid briefly), update the
secret in the secrets manager, redeploy or restart the application to pick
up the new value, and only then revoke the old key once the new one is
confirmed live. Per-tenant Stripe Connect account IDs (`tenants
.stripe_connect_account_id`) are identifiers, not secrets, and aren't part
of this rotation concern.

**Test-mode vs. live-mode separation:** local and staging always run against
Stripe test-mode keys and test-mode webhooks; production always runs against
live-mode. No environment mixes the two. The application should assert this
at boot — e.g., refuse to start if `APP_ENV=production` but the configured
Stripe secret key has a `sk_test_` prefix, or vice versa — as a cheap,
concrete guard against the single most damaging configuration mistake
available here (real money moving in a non-production environment, or a
production deploy silently still pointed at test mode).

**What must be verified manually before the first live payment:** this is
`07-testing-strategy.md`'s own "What genuinely cannot be tested
automatically" list (full Stripe Connect Express onboarding UX against a
real identity, actual reminder-email/SMS deliverability, a real dispute's
full lifecycle, real bank payout timing) — that list *is* this project's
manual pre-launch checklist. It is referenced here, not duplicated, per `07`'s
own forward pointer; whoever runs the first real pilot launch should walk
`07`'s list directly rather than a second copy of it that could drift out of
sync with the original.

## Observability and the CI gate

**What must be monitored because of this design specifically, not generic
infrastructure monitoring:**

- **`23P01` (exclusion-constraint violation) rate, not just its handling.**
  Every occurrence is already correctly translated to a `409
  SLOT_ALREADY_BOOKED` response (D-0007) rather than a 500 — but a *rising
  rate* of these is a real product signal (heavy contention on popular
  slots, or a bug serving stale/incorrect availability that leads customers
  to keep hitting already-taken slots), not merely a correctly-handled error
  to ignore because it isn't a crash. Alert on a rate increase over a
  rolling baseline, not merely on the event's raw presence.
- **RLS policy failures.** `07`'s tenant-isolation suite's manifest-
  completeness and RLS-enforcement catalog checks (`pg_class.relrowsecurity`
  /`relforcerowsecurity`, policy presence) are CI-time, pre-deploy checks —
  but nothing stops a manual, out-of-band database change from disabling RLS
  on a table in production after deploy. Re-run the same catalog check
  against production itself on a recurring schedule (e.g. daily) as a
  defense-in-depth measure against drift that never went through the
  migration path CI actually gates.
- **Webhook delivery failures, in both directions.** Stripe-to-us: a
  `stripe_webhook_events` row whose `processing_error` persists across
  Stripe's own retry window is a real incident, not a transient blip — alert
  on it directly, don't rely on someone noticing a support ticket instead.
  Us-to-Stripe: synchronous calls to Stripe's API during booking creation or
  refund issuance failing (network, rate limit, API error) is a distinct
  failure mode from webhook processing and needs its own visibility, since
  the two are diagnosed differently.
- **Off-session balance-charge decline rate (J5).** A single decline is an
  *expected*, handled outcome (FR-10) — the metric that matters is whether
  the rate is climbing, which would indicate either a real pattern (expired
  cards, a fraud wave) or an integration bug (a stale saved payment method
  reference, a wrong Connect charge parameter) disguising itself as ordinary
  declines.

**Background jobs, restated from the Session 0/1 stub this file supersedes:**
reminder delivery (email/SMS) and Stripe webhook processing both run via
Redis-backed Laravel queues (Horizon), per `03-architecture.md`, with
retry/backoff rather than synchronously in the request cycle — and, per
D-0009, every queued job extends `TenantScopedJob` (or the tenant-less
`PlatformJob` base for non-tenant-scoped housekeeping like purging
`stripe_webhook_events`) so tenant context is never ambient. Deliverability
tuning itself (avoiding spam-folder placement) remains what `00`'s "what
must remain private forever" list calls it — this document states that
deliverability is monitored, never the specific tuning used to achieve it.

**`composer ci:check` does not exist.** It is referenced throughout
`07-testing-strategy.md` as the fast-gate entrypoint, but there is no
`composer.json`, no PHP, and no application code anywhere in this repository
yet — stated plainly here rather than left as an implicit assumption. What
it must contain, once the first implementation session creates it, drawn
directly from `07` and this session's rulings rather than invented fresh:

- Static analysis and coding-style checks.
- All unit tests.
- The feature/integration tests that fit inside a single rolled-back-
  transaction wrapper against a real, containerized Postgres instance.
- The full tenant-isolation suite (`07`'s manifest-completeness check,
  RLS-enforcement catalog check, and all concrete cases) as its own named,
  visibly-timed step — carrying the **under-60-second runtime budget**
  D-0017 sets for it specifically, not folded silently into an undifferen-
  tiated "feature tests" bucket where an overrun would go unnoticed.
- **Explicitly excluded from `composer ci:check`** (the fast gate), per
  `07`'s nightly/pre-deploy tier: the true-concurrency, separate-connection
  DB-constraint tests; the real-Stripe-test-mode payment subtier; the single
  J1 E2E browser smoke test (D-0018).

**Amendment (Session 8, 2026-08-24) — `composer ci:check` now exists,
matching this prediction with two scope notes.** `composer ci:check` runs
`pint --test` (style), `phpstan analyse` via Larastan (static analysis,
level 5), then `pest --exclude-group=slow` (the fast test suite, tenant-
isolation included) — exits 0, verified. Actual runtime: ~7 seconds
wall-clock for the whole gate; the tenant-isolation suite alone (`composer
test:tenant-isolation`), the piece D-0017 puts a 60-second budget on, runs
in ~3 seconds. Both are far under budget, as expected for a suite whose case
count is manifest-driven rather than open-ended (D-0017's own reasoning).
Two differences from the prediction above, both scope-driven rather than
contradictions:
- **No unit or feature tests exist yet, so those two bullets are currently
  empty categories inside the fast gate, not populated ones.** This
  session's hard scope boundary was schema, tenant-context plumbing, and
  the tenant-isolation suite only — no controllers, endpoints, or business
  logic exist yet for a feature test to exercise, and no pure-logic code
  (deposit calculation, buffer arithmetic, etc.) exists yet for a unit test
  to exercise either. `composer.json` already carries `test:unit`/
  `test:feature` scripts pointed at empty directories, ready for a future
  session to fill in — not invented placeholder tests.
- **The tenant-isolation suite runs as part of one combined `pest` invocation
  in `ci:check`, not as its own separately-timed CI step.** Its runtime is
  independently measurable via `composer test:tenant-isolation` (used above
  to confirm the 60-second budget), and Pest's own JSON output reports pass/
  fail per suite — but wiring a real CI pipeline (e.g., a dedicated GitHub
  Actions job/step boundary around just that suite, per D-0017's "visibly-
  timed step" framing) is real CI-configuration work this session didn't do,
  since no CI pipeline configuration exists yet in this repository at all.
- **Two Postgres connections, not one, per D-0009/D-0020's already-decided
  role split:** `pgsql` (`bookslot_app`, the runtime/test-query connection)
  and `pgsql_migrator` (`bookslot_migrator`, used only to run migrations,
  including the one-time schema setup `composer ci:check`'s own test run
  triggers via a `RefreshDatabase` override — see
  `tests/Concerns/RefreshesTenantDatabase.php`). This isn't a deviation from
  either decision, just the first time it's been reflected in actual
  application/test configuration rather than only in prose.

## Open questions

**Needs a real pilot or real hosting budget (not resolvable by more design
work):**
- Which of the three checked providers (or Google Cloud SQL) to actually
  use, in which region, at which pricing tier — all clear the `btree_gist`
  requirement; choosing between them is a cost/geography decision, not a
  technical one this document can settle in the abstract.
- Whether the 7-day PITR window / 30-day snapshot retention proposed above
  is enough once real tenant data (and its real business value to a studio)
  exists, or should be extended — a cost-scaling knob, not a blocker.
- Concrete alerting thresholds (e.g., what specific `23P01` rate or decline
  rate counts as "rising") — there is no real traffic baseline yet to set a
  meaningful number against; monitoring the right things (named above) can
  start before the right thresholds are known.

**Not open — decided in this document or by this session's rulings, per
D-0011 through D-0020 and the reasoning above.**
