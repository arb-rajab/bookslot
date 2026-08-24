# Session Handoff

## Project
- Repository: `bookslot` (working name — see `00-project-brief.md`),
  https://github.com/arb-rajab/bookslot
- Public or private: **private** (private-track — no public counterpart,
  not subject to the public portfolio's framework-allocation-ledger rule)
- Product/domain: booking, deposits, and no-show protection for
  appointment-based service businesses (illustrative vertical: tattoo
  studios — see `00-project-brief.md`)
- Current version or branch: `main`, no tags, no application code yet.

## Session completed
- Session number and title: **Session 3 — Gap Resolution and Testing
  Strategy**
- Objective: resolve three correctness gaps found in review of Session 2's
  decisions (D-0005/D-0006/D-0007), then produce `07-testing-strategy.md`.
  No application code, no test files, no PHPUnit/Pest scaffolding in scope,
  per this session's explicit constraint — Phase 2 is documentation only.
- Status: **complete** for this session's defined scope.

## Work completed

**Phase 1 — three gaps resolved, each presented with options/tradeoffs and
approved by the user before being written:**

- **G1 (buffer unenforceable at the DB layer):** D-0007's buffer, enforced
  only at slot-generation (a read), left a real concurrency gap — two
  requests could each read availability before either committed and produce
  bookings that don't literally overlap but violate buffer. Resolved as
  **D-0008**: `appointments` gains `buffer_before_minutes`/
  `buffer_after_minutes` (snapshotted per-row at booking-creation time, not
  looked up live) and a generated `occupancy_range` column; the exclusion
  constraint moves from `appointment_range` to `occupancy_range`.
  `appointment_range` stays the literal, customer-facing window. Handles
  variable per-service/per-artist buffer correctly (per-row snapshot),
  handles a studio changing its buffer later (existing bookings keep their
  original buffer — deliberate grandfathering), and preserves legitimate
  zero-buffer back-to-back bookings.
- **G2 (RLS tenant-context lifecycle undefined):** resolved as **D-0009**:
  every tenant-scoped HTTP request and queued job runs inside an explicit
  transaction with the tenant GUC set via parameterized
  `set_config('app.current_tenant_id', ?, true)` as its first statement
  (never a session-level `SET`, never string-interpolated). Named three
  roles' worth of behavior: `bookslot_app` (the only runtime role, always
  RLS-subject), `bookslot_migrator` (owns tables, holds `BYPASSRLS`, used
  only offline/CI — migrations, seeders, backfills, `pg_dump`). The
  platform-admin cross-tenant path was **revised away from a literal
  `BYPASSRLS` role** (D-0005's original phrasing) to tenant impersonation
  under the ordinary app role plus an app-layer authorization check — this
  keeps RLS fail-closed even on the admin path itself, so there is exactly
  one RLS-bypass surface in the system and it's never reachable from a live
  request. Also specified: PgBouncer transaction-mode compatibility (a
  forward constraint on `08`), queue-job/retry/batch behavior via a
  `TenantScopedJob` base class, and how the unauthenticated public-booking
  path derives tenant context safely from a `{slug}` path parameter (never
  from client-supplied `tenant_id`).
- **G3 (off-session mandate evidence unspecified):** resolved as **D-0010**:
  a new `payment_mandates` table stores the full rendered mandate text as
  agreed to (not just a template-version pointer), plus
  timestamp/IP/user-agent/PaymentIntent-ID/payment-method-ID, linked into
  the existing `booking_events` audit trail. Covers only the J5 off-session
  balance charge (no-show forfeiture is disclosure, not a new-charge
  mandate, since it retains already-captured funds). Reconciled with `06`'s
  already-flagged disclosure-copy open question: that one is owner-facing
  (dispute-liability exposure); this one is customer-facing (the future
  charge) — related but distinct, both still deferred to a future
  copywriting/implementation session.

**Phase 2 — `07-testing-strategy.md` written in full:** test layers
(unit/feature/DB-constraint/contract/E2E) with what each deliberately does
NOT test and an expected count/runtime ratio reasoned from solo-track suite-
runtime cost; a dedicated tenant-isolation suite with concrete cases
(raw-query/`DB::statement` bypass, queue jobs without context, jobs retried
after a context change, cross-tenant ID guessing generated from `05`'s
endpoint table, eager-loading traversal, the `BYPASSRLS` role's
unreachability from runtime, and a manifest-plus-catalog-introspection
mechanism that fails loudly on a new table shipped without RLS); concurrency
and slot-integrity cases (true multi-connection tests, `23P01→409` mapping,
`'[)'` boundary cases with and without buffer, DST-pinned fixtures,
cancelled-slot rebooking, buffer-under-race) with an explicit, unsoftened
statement that SQLite cannot express any of this (`tstzrange`, GIST, RLS all
absent) and isn't used anywhere in the suite; payment-flow testing (faked
Stripe client by default, a narrower real-test-mode subtier, webhook
signature/idempotency/out-of-order cases, SCA decline, refunds, disputes)
with an explicit manual pre-launch checklist for what can't be automated;
time/timezone fixture strategy; multi-tenant factory strategy; a CI split
between the fast `composer ci:check` gate (tenant-isolation suite
non-negotiably included) and a slower nightly/pre-deploy tier; and a
coverage philosophy that names required paths instead of fabricating a
percentage target.

## Files created or changed
- `docs/project-memory/09-decision-log.md` — added D-0008, D-0009, D-0010.
- `docs/project-memory/04-data-model.md` — amended: ERD gains
  `payment_mandates`; `appointments` gains buffer columns + `occupancy_range`
  and the exclusion constraint now targets it; new `payment_mandates` table;
  tenancy-boundary section amended with the full GUC lifecycle; migration
  order updated; open questions updated.
- `docs/project-memory/03-architecture.md` — added a Session 3 amendment
  section summarizing D-0009's RLS lifecycle/roles/admin-path revision.
- `docs/project-memory/06-security-threat-model.md` — added a Session 3
  amendment section covering all three gaps' security implications and
  reconciling the mandate-evidence item with the existing disclosure-copy
  open question.
- `docs/project-memory/07-testing-strategy.md` — full rewrite (was a stub).
- `docs/project-memory/12-session-handoff.md` — this file.
- **Not touched, per this session's explicit constraint:** `02`, `05`, `08`,
  `10`, `11`, `13`, `14`. `05` needs a small future addition (a mandate-
  acceptance field on the booking-creation request) — noted as an open item
  in `04`/D-0010, not made this session.

## Decisions made
See `09-decision-log.md` D-0008 through D-0010 for full entries with
rejected alternatives. Summary: buffer moves into a second, generated
`occupancy_range` column that carries the exclusion constraint; RLS tenant
context is transaction-scoped `set_config(..., true)` with three named
roles and an impersonation-based (not `BYPASSRLS`-based) admin path; off-
session balance-charge consent is evidenced by a full rendered-text
mandate-evidence table linked into the existing audit trail.

## Validation performed
- No application code exists yet, so no tests/lint/build were run — this
  session's deliverables are schema/decision-log amendments and testing-
  strategy documentation, per its explicit constraint.
- Each of the three gaps was presented to the user as options with
  tradeoffs and a recommendation before any file was written; all three
  recommendations were approved before Phase 1 amendments were made.
- Cross-checked every amendment against `02`–`06`/`09` for contradictions
  before writing (none found beyond the deliberate, flagged revision of
  D-0005's `BYPASSRLS`-for-admin phrasing, which is recorded as a revision,
  not a silent overwrite).

## Amendment (Session 4, 2026-08-24) — D-0008's DDL corrected by execution testing

Session 3's `occupancy_range` DDL (recorded above) was written but never
executed. This session ran it against a real PostgreSQL 17.11 scratch
database and it failed (`42P17: generation expression is not immutable`).
Resolved by moving the arithmetic into a new, explicitly `IMMUTABLE`
`occupancy_window()` function (hardened inline with
`SET search_path = pg_catalog, pg_temp` at `CREATE FUNCTION` time, not via a
later `ALTER FUNCTION`), and adding a containment CHECK
(`occupancy_range @> appointment_range`) as the invariant-of-record — proven
by execution to catch the non-inverting `buffer_before_minutes = -5` case
that the buffer-bounds CHECKs alone would otherwise leave silent. Full
findings, rejected fix options, and the PostgreSQL-12 version-floor
justification: `09-decision-log.md`'s D-0008 amendment. Corrected DDL:
`04-data-model.md`'s D-0008 amendment. New test cases regressing these
findings (recompute-and-compare, buffer rejection, `search_path` shadowing,
post-restore verification): `07-testing-strategy.md`. The complete DDL was
re-run top to bottom in a fresh scratch database after editing and
succeeded with no errors, confirming the migration order still holds.

Files touched this session: `09-decision-log.md`, `04-data-model.md`,
`07-testing-strategy.md`, this file. `03-architecture.md` and
`06-security-threat-model.md` were read for context but not edited this
session — their Session 3 amendments already reflect the D-0009 lifecycle
and mandate-evidence decisions and needed no further change for this
session's DDL-correction scope.

## Open questions and risks

**Needs a real pilot to answer (not resolvable by more design work):**
- Whether slot computation needs to become materialized under real traffic
  (unchanged from Session 2).
- Whether the illustrative reminder cadence is actually effective (R-04,
  unchanged).
- What level of simulated concurrency beyond the correctness-proving minimum
  (two simultaneous requests) is worth a dedicated test — unknown until real
  booking traffic exists (`07`).

**Needs your ruling (not a pilot):**
- FR-16, no-show rebooking-prompt default, and the exact hold-window
  duration — all unchanged, carried over from Session 2.
- Whether MVP defaults buffer to "after only" or allows both before/after
  per service (D-0008) — schema supports either, no default chosen.
- Exact mandate wording/copy and whether a formal Stripe SCA mandate flow is
  required for a given card scheme/region (D-0010) — real implementation-
  session research, not answered here.
- **Open item, not yet made: a mandate-acceptance field on
  `05-api-contracts.md`'s `POST /api/tenants/{slug}/bookings` request body
  (endpoint 2 in `05`'s endpoint table).** D-0010 requires "an explicit
  affirmative action (a checkbox) before the deposit PaymentIntent is
  created" — the current documented request body (`service_id`, `staff_id`,
  `starts_at`, `customer`) has no field carrying that acceptance. This was
  flagged as an open item in `04-data-model.md`'s `payment_mandates` table
  notes when D-0010 was written (Session 3) and is repeated here because it
  has not moved since: `05` was explicitly out of scope for Session 3 and
  again for this session (Session 4), so the field still does not exist in
  the documented contract. Whoever next has `05` in scope should add it
  (e.g. a boolean `mandate_accepted` alongside the existing request fields)
  before that endpoint is treated as fully specified.
- Whether an E2E browser test belongs in MVP scope at all, or is deferred
  entirely post-pilot (`07`).
- Confirm the tenant-isolation suite should block the fast `composer
  ci:check` gate (recommended as non-negotiable in `07`) rather than run
  only nightly.
- Test framework choice (Pest vs. PHPUnit) — deliberately left open.

R-01 through R-06 in `10-risk-register.md` are unchanged by this session —
none of this session's work closes or newly opens a risk register entry;
worth revisiting once the open-question list above accumulates further.

## Next recommended session

- Proposed session title: **Session 4 — Pilot Discovery, or
  Deployment/Operations (`08`)**.
- Single objective: as in Session 2's handoff, a real candidate pilot studio
  becoming available should take priority over further design work
  (R-01/B-01 remain the standing top risk, untouched by two design-focused
  sessions in a row now). Absent that, `08-deployment-and-operations.md` is
  the natural next design-side session: it now has concrete inputs to
  resolve against — `04`'s migration-order note on `btree_gist`/
  `gen_random_uuid()`/`pgcrypto` version dependencies, and `09`
  D-0009's forward constraint that hosting/pooling must preserve
  transaction-scoped GUC isolation (PgBouncer transaction-mode is
  compatible; anything that promotes `SET LOCAL` to session-level `SET` is
  not).
- Inputs required: this Project Memory Pack, particularly `04` and `09`
  D-0008/D-0009/D-0010 from this session, and `07` for what a hosting choice
  must not break.
- Expected deliverables: either a real pilot discovery record, or a
  complete `08-deployment-and-operations.md`.
- Definition of done: whichever path is taken, the relevant Project Memory
  Pack files are updated with real content, and this handoff file reflects
  the new session.

## Paste-into-new-session context
<!-- Self-contained block. NEVER include credentials, private URLs, customer
     data, proprietary business rules, or sensitive security details. -->

`bookslot` is a private-track repository (GitHub: `arb-rajab/bookslot`) — a
booking/deposits/no-show-protection SaaS for appointment-based small
businesses, illustratively anchored on tattoo studios. Session 3 (this
session) resolved three correctness gaps found in review of Session 2's
decisions, then wrote the full testing strategy. Resolved: (1) buffer/
turnaround time now enforced via a second, generated `occupancy_range`
column that carries the double-booking exclusion constraint, with the
buffer amount snapshotted per-row at booking time so a studio's later
buffer-config change never retroactively changes an existing booking; (2)
the RLS tenant-context GUC is set via transaction-scoped, parameterized
`set_config(..., true)` for every request and queued job, with three named
Postgres roles and a platform-admin path that impersonates a specific tenant
under the ordinary app role rather than using a live `BYPASSRLS` role
(revising D-0005's original phrasing) — so there is exactly one RLS-bypass
surface in the system, reserved for offline migration/backfill tooling
only, never reachable from a live request; (3) the off-session balance
charge now has evidenced customer consent — a `payment_mandates` table
storing the full agreed-to text (not just a template reference) plus
IP/timestamp/user-agent/Stripe IDs, linked into the existing audit trail.
Full details and rejected alternatives: `09-decision-log.md` D-0008/D-0009/
D-0010. `07-testing-strategy.md` is now real, not a stub — test layers,
a concrete tenant-isolation suite (with a manifest mechanism that fails
loudly on a new unprotected table), concurrency/slot-integrity cases (real
Postgres required — SQLite cannot express `tstzrange`/GIST/RLS and isn't
used anywhere in the suite), payment-flow testing split between a faked
Stripe client (default) and a narrower real-test-mode subtier, and a CI
split between a fast gate and a slower nightly tier. `04` and `03`/`06`
carry dated Session 3 amendment sections rather than silent edits, same
discipline as Session 2. Still true: no real pilot customer, pricing, or
production data exists; R-01 remains the standing top risk, untouched by two
design sessions in a row. See this file's "Open questions and risks" section
for what's newly open, several of which explicitly need your ruling rather
than a pilot.
