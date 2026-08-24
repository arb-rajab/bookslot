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

## Amendment (Session 5, 2026-08-24) — nine outstanding rulings recorded, deployment and operations written

**Phase 0 — the missing contradiction list (a prior session was asked for
this and did not deliver it).** Checked `02`/`05` against everything decided
after them. Full table delivered in this session's chat output, not
duplicated here in full; the two findings **not** resolved by this session's
rulings (and therefore still open, carried below) are:

1. **`05-api-contracts.md`'s platform-admin endpoint row still describes the
   admin path as "the explicit `BYPASSRLS`-equivalent path."** This is
   factually superseded by D-0009 (Session 3), which revised that mechanism
   to tenant impersonation under the ordinary `bookslot_app` role plus an
   app-layer `platform_admin` check — `BYPASSRLS` is reserved exclusively
   for `bookslot_migrator`'s offline/manual use. `05`'s wording was not
   corrected this session (none of the nine rulings covered it, and fixing
   it wasn't authorized scope) — a real, live contradiction, severity
   **contradicts**, left as an open item below rather than quietly fixed.
2. **`02-requirements.md`'s J3 narrative and NFR-03 still describe the
   double-booking guarantee purely in terms of D-0007's literal
   `appointment_range` overlap check**, not D-0008's later, buffer-aware
   `occupancy_range` exclusion constraint. The guarantee they describe still
   holds today (D-0008 strengthened it, it didn't weaken it) — severity
   **incomplete**, not contradicts — but the cited mechanism is stale. Not
   fixed this session for the same reason as #1.

Two other findings from the same audit *were* resolved as part of this
session's rulings, not left open: `04-data-model.md`'s
`buffer_before_minutes`/`buffer_after_minutes` `DEFAULT 0` (contradicted the
incoming R2 ruling — fixed via D-0012) and `05`'s missing `mandate_accepted`
field on booking creation (contradicted D-0010's requirement — fixed via
D-0015(b)).

**Phase 1 — nine rulings recorded as `09-decision-log.md` D-0011 through
D-0019** (plus D-0020, a tenth decision this session's own Phase 2 work
surfaced — see below), each with a genuine decision entry, options
considered, and a "must not be silently reversed because" line, per this
pack's convention:

- **D-0011 (R1):** hold window = 15 minutes, as a configuration value, not a
  hard-coded constant. The *number* stays pilot-dependent (see Open
  questions below); the *mechanism* (config, not constant) is settled.
- **D-0012 (R2, amends D-0008):** buffer has no default — `services` gains
  required `buffer_before_minutes`/`buffer_after_minutes` columns (this also
  closes a real gap: D-0008's snapshot mechanism referenced a "service
  buffer configuration" that had never actually been added to the schema
  until now). `appointments`' own buffer columns lose their `DEFAULT 0`.
- **D-0013 (R3):** FR-16 resolved — own bookings only at MVP; a widening
  toggle is a named Paid-tier feature, not a vague maybe.
- **D-0014 (R4):** no-show rebooking prompt confirmed off; a new FR-23
  records the separate manual re-invite capability.
- **D-0015 (R5, split):** consent copy/SCA research stays deferred (a);
  `mandate_accepted`/`mandate_template_version` added to `05`'s
  booking-creation request now (b) — closing the Session-3-old open item.
- **D-0016 (R6):** test framework — Pest.
- **D-0017 (R7):** tenant-isolation suite blocking the fast gate is settled
  (not "confirm"), with an explicit **under-60-second** runtime budget and a
  stated escalation path (optimize → shard → move only non-core cases out)
  if that budget is exceeded.
- **D-0018 (R8):** the single J1 E2E smoke test is settled as the entire MVP
  E2E scope (not "a recommendation").
- **D-0019 (R9):** statement descriptor and dispute-disclosure copy stay
  deferred, with a technical note recorded (Stripe's 5–22 character/charset
  constraints on statement descriptors, and that Connect charge-type/
  `on_behalf_of` combination determines which party's descriptor the
  customer actually sees).

**Phase 2 — `08-deployment-and-operations.md` written in full**, superseding
Session 0/1's deferred stub: PostgreSQL 17 chosen and justified on support
lifecycle (not novelty) against a 12-floor/17-deployed distinction already
implied by `04`; `btree_gist` verified (not assumed) against AWS RDS,
Supabase, and Neon, with Google Cloud SQL checked as a fourth data point;
database-role credential placement resolved honestly — `bookslot_migrator`
kept out of the general CI/CD secret store as a new decision (**D-0020**,
which refines D-0009's "offline, human- or CI-triggered" phrasing into an
explicit choice), with the automation cost this trades away stated plainly;
PgBouncer transaction mode chosen for pooling, with **AWS RDS Proxy
specifically flagged as incompatible** with D-0009's `set_config` pattern
(it pins connections on any session/transaction-scoped configuration
change, silently defeating pooling rather than breaking correctness);
backup/restore cadence and retention proposed with the recompute-and-compare
check wired in as a standard post-restore step covering all three cases `07`
named as exposed; environment topology, expand/contract migration safety,
and a "roll forward, not back" rollback policy for data-bearing migrations;
Stripe operational surface (per-environment webhook secrets, key rotation,
test/live separation with a boot-time assertion, pointing at `07`'s manual
pre-launch checklist rather than duplicating it); and observability tied
specifically to this design (`23P01` rate, RLS-policy drift re-checked in
production, webhook delivery failures in both directions, off-session
decline rate), plus a plain statement that `composer ci:check` doesn't exist
yet and exactly what it must contain when it does.

**`06-security-threat-model.md` gained one amendment** (not part of the nine
rulings, but surfaced by writing `08`): recording D-0020's migrator-
credential-placement decision as a threat-model-relevant control.

Files touched this session: `09-decision-log.md` (D-0011–D-0020),
`02-requirements.md` (FR-16, J10, new FR-23, open-questions cleanup),
`04-data-model.md` (`services` buffer columns, `appointments` buffer
defaults removed, open-questions cleanup), `05-api-contracts.md`
(`mandate_accepted`/`mandate_template_version`, staff-endpoint scope),
`06-security-threat-model.md` (D-0020 amendment),
`07-testing-strategy.md` (framework, CI-gate budget, E2E scope settled),
`08-deployment-and-operations.md` (written in full), this file.
**Not touched, per this session's explicit constraints:** `10`, `11`, `13`,
`14`. **Not touched despite being named by the Phase 0 audit, since fixing
them wasn't covered by the nine rulings and the session was told not to fix
findings outside the rulings:** `05`'s stale `BYPASSRLS`-equivalent admin
wording; `02`'s J3/NFR-03 stale exclusion-constraint description — both
carried below as open items, not silently resolved.

## Open questions and risks

**Needs a real pilot to answer (not resolvable by more design work):**
- Whether slot computation needs to become materialized under real traffic
  (unchanged from Session 2).
- Whether the illustrative reminder cadence is actually effective (R-04,
  unchanged).
- What level of simulated concurrency beyond the correctness-proving minimum
  (two simultaneous requests) is worth a dedicated test — unknown until real
  booking traffic exists (`07`).
- **Whether 15 minutes is actually the right `pending_payment` hold window**
  (D-0011) — the mechanism (a configuration value) is settled; the number
  itself is an explicit guess pending real booking-funnel data.
- `08`'s hosting choices left open for the same reason: which of the three
  checked providers (AWS RDS, Supabase, Neon — or Google Cloud SQL) to
  actually use, at which region/tier; whether the proposed 7-day PITR/
  30-day snapshot retention is enough once real tenant data exists; and
  concrete alerting thresholds (no real traffic baseline exists yet to set
  a meaningful number against).

**Needs your ruling (not a pilot) — genuinely still open after this
session's nine rulings:**
- **Still open from Session 3 (D-0008), unresolved by D-0012's "no default"
  ruling:** whether MVP restricts buffer to "after only" (cleanup time
  following a service) or allows both before and after per service — the
  schema supports either; this session only decided that a value must be
  chosen explicitly, not which scope MVP restricts it to.
- **Still open from Session 3 (D-0010), unresolved by D-0015(a):** exact
  mandate wording/copy, and whether a formal Stripe SCA mandate flow is
  required for a given card scheme/region — deliberately deferred as
  legal-adjacent implementation-session research, not this session's to
  answer. D-0019's technical note (statement-descriptor constraints,
  Connect charge-type/`on_behalf_of` dependency) is relevant background for
  whoever picks this up, not a substitute for doing it.
- **New, from this session's Phase 0 audit, not covered by any of the nine
  rulings — carried forward, not fixed:**
  - `05-api-contracts.md`'s platform-admin endpoint row still describes the
    admin path as "the explicit `BYPASSRLS`-equivalent path," which D-0009
    (Session 3) already superseded — the real mechanism is tenant
    impersonation under the ordinary `bookslot_app` role plus an app-layer
    check. Severity: contradicts. A small, low-risk wording fix once `05`
    is back in scope for something.
  - `02-requirements.md`'s J3 narrative and NFR-03 still describe the
    double-booking guarantee purely via D-0007's literal `appointment_range`
    overlap, not D-0008's later buffer-aware `occupancy_range` mechanism.
    Severity: incomplete (the guarantee still holds; the described
    mechanism is stale). A wording fix once `02` is back in scope.
  - `05`'s owner-service-management endpoints (`POST`/`PATCH
    /api/owner/services`) don't yet reflect D-0012's new required
    `buffer_before_minutes`/`buffer_after_minutes` fields on `services` —
    flagged when D-0012 was written, not fixed here since `05`'s service-
    management endpoints weren't named in this session's authorized scope.
  - `05`'s manual re-invite action (D-0014/FR-23) has no endpoint shape yet
    — recorded as a decided capability, not a spec, per D-0014.

None of the above blocks anything currently written; they're wording/
completeness gaps in already-committed documents, not open design
questions.

R-01 through R-06 in `10-risk-register.md` are unchanged by this session —
none of this session's work closes or newly opens a risk register entry.

## Next recommended session

- Proposed session title: **Session 6 — Pilot Discovery, or `05`'s Remaining
  Endpoint Gaps.**
- Single objective: as in every prior handoff, a real candidate pilot studio
  becoming available should take priority over further design work — R-01
  remains the standing top risk, untouched by four design-focused sessions
  in a row now (Sessions 2 through 5). Absent that, the natural next
  design-side session is closing the small, named `05-api-contracts.md`
  gaps this session's audit surfaced and didn't fix (the stale
  `BYPASSRLS`-equivalent admin wording, the missing service-buffer fields on
  the owner service-management endpoints, and the undefined manual
  re-invite endpoint) — each is small and low-risk individually, but they're
  now three separate, named, tracked gaps rather than one vague "05 needs a
  pass" note.
- Inputs required: this Project Memory Pack, particularly `09` D-0009/
  D-0012/D-0013/D-0014/D-0015 for exactly what `05` needs to reflect, and
  this file's Open Questions section above for the precise list.
- Expected deliverables: either a real pilot discovery record, or `05`'s
  three named gaps closed with dated amendments (not silent edits).
- Definition of done: whichever path is taken, the relevant Project Memory
  Pack files are updated with real content, and this handoff file reflects
  the new session.

## Paste-into-new-session context
<!-- Self-contained block. NEVER include credentials, private URLs, customer
     data, proprietary business rules, or sensitive security details. -->

`bookslot` is a private-track repository (GitHub: `arb-rajab/bookslot`) — a
booking/deposits/no-show-protection SaaS for appointment-based small
businesses, illustratively anchored on tattoo studios. Session 5 (this
session) had three parts. **First**, it delivered a contradiction audit a
prior session had been asked for and never produced, checking `02`/`05`
against everything decided after them — two real findings surfaced and were
left open rather than silently fixed (`05`'s admin-endpoint text still
describes a `BYPASSRLS`-equivalent mechanism D-0009 already replaced with
tenant impersonation; `02`'s double-booking narrative still cites D-0007's
literal-range check rather than D-0008's later buffer-aware
`occupancy_range` mechanism — both are wording/completeness gaps, not live
bugs, since the underlying system behavior is correct either way). **Second**,
it recorded nine outstanding rulings as decision-log entries D-0011–D-0019
(hold window = 15 minutes as a config value, not a constant; buffer has no
default and is now a required field on `services`, closing a real gap where
D-0008's snapshot source had never actually been added to the schema; FR-16
staff visibility settled to own-bookings-only with a Paid-tier toggle;
no-show rebooking confirmed off with a separate manual re-invite capability
added as FR-23; the mandate-acceptance field split from the still-deferred
consent-copy/SCA research and added to `05` now; Pest as the test framework;
the tenant-isolation suite's CI-blocking status settled with a stated
60-second runtime budget and an explicit escalation path if exceeded; the
single J1 E2E smoke test settled as the entire MVP E2E scope; and the
statement-descriptor/dispute-copy item kept deferred with a technical note
on Stripe's descriptor constraints and Connect charge-type dependency).
**Third**, it wrote `08-deployment-and-operations.md` in full: PostgreSQL 17
chosen on support-lifecycle grounds (not the newest version, not the one
about to go EOL); `btree_gist` verified against three real managed providers
rather than assumed; a new decision (D-0020) keeping the sole
`BYPASSRLS`-capable credential (`bookslot_migrator`) out of the general
CI/CD pipeline entirely, with the automation cost stated plainly; PgBouncer
transaction mode chosen for pooling, with AWS RDS Proxy specifically flagged
as incompatible with this project's `set_config`-per-transaction pattern
(it silently defeats pooling rather than breaking correctness); backup/
restore procedure wiring in `07`'s recompute-and-compare integrity check as
a standard post-restore step; migration safety via expand/contract and a
roll-forward-not-back policy for data-bearing migrations; and observability
tied specifically to this design's own failure modes rather than generic
infrastructure monitoring. Full details and rejected alternatives:
`09-decision-log.md` D-0011 through D-0020. Every affected document
(`02`, `04`, `05`, `06`, `07`, plus the new `08`) carries a dated Session 5
amendment section rather than a silent edit, same discipline as every prior
session. Still true: no real pilot customer, pricing, or production data
exists; R-01 remains the standing top risk, now untouched by four
design-focused sessions in a row. See this file's "Open questions and
risks" section above for the full current list, split explicitly into what
needs a pilot vs. what still needs a ruling.
