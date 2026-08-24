# Session Handoff

## Project
- Repository: `bookslot` (working name — see `00-project-brief.md`),
  https://github.com/arb-rajab/bookslot
- Public or private: **private** (private-track — no public counterpart,
  not subject to the public portfolio's framework-allocation-ledger rule)
- Product/domain: booking, deposits, and no-show protection for
  appointment-based service businesses (illustrative vertical: tattoo
  studios — see `00-project-brief.md`)
- Current version or branch: `main`, no tags. Application code exists as of
  Session 8: Laravel API scaffold, database schema/migrations, tenant-context
  plumbing, and the tenant-isolation test suite — no controllers, routes,
  Stripe integration, or frontend yet. See the Session 8 amendment below.

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

## Amendment (Session 6, 2026-08-24) — 05 reconciled, missing D-0008 test added

**Objective:** reconcile `05-api-contracts.md` with every decision made
since it was written in Session 2, close the reporting gap in Session 5's
contradiction audit (it omitted two of the checks it was asked to perform),
and add the D-0008 invariant test that four sessions of otherwise-passing
verification never actually exercised.

**Phase 0 — completed the contradiction audit.** Session 5's table listed
four findings but omitted the webhook-list-vs-D-0006 check and the
02-NFRs-vs-07 check the prompt required, with no way to tell an unchecked
item from a verified-clean one. Redone completely this session, every check
reported with an explicit verdict (CONTRADICTS / INCOMPLETE / CLEAN):

| # | Check | Verdict | Finding |
|---|---|---|---|
| 1 | `05`'s webhook list vs. D-0006's second (off-session balance) charge event | INCOMPLETE | No webhook *type* was missing — `payment_intent.succeeded`/`payment_intent.payment_failed` already cover a `balance`-type PaymentIntent structurally (looked up by `stripe_payment_intent_id`, not hard-coded to `deposit`) — but the table's wording only called out the `deposit` case and never stated the idempotency relationship to endpoint 6's synchronous response. Fixed. |
| 2 | `05`'s booking-creation/payment endpoints vs. D-0006's immediate-capture PaymentIntent + `setup_future_usage` | CLEAN | `setup_future_usage`/off-session saving is a server-internal Stripe param with no reason to appear in the client-facing contract; nothing in the request/response shapes contradicts it. |
| 3 | `05`'s error responses vs. D-0007's `23P01 → 409 SLOT_ALREADY_BOOKED` | CLEAN | Endpoint 2 documents exactly this mapping. |
| 4 | `05`'s public booking-page endpoints vs. D-0009's unauthenticated tenant-context derivation | INCOMPLETE | Slug-based endpoints and the token-based manage-booking endpoints both match D-0009's two named mechanisms. **`POST /api/bookings/{id}/confirm-payment` matches neither** — it's public, unauthenticated, and addressed only by an unscoped `appointment_id`, with no `{slug}` and no signed token to derive tenant context from before an RLS-protected lookup. D-0009 doesn't name a third mechanism. Not fixed — raised as an open item (see below), per this session's instruction not to invent a missing detail in a later decision. |
| 5 | `02`'s NFRs vs. everything `07` established | INCOMPLETE (NFR-03 only) | NFR-01/02/04/05/06 all have direct, matching coverage in `07`. NFR-03 cited only D-0007 (literal-range exclusion), stale since D-0008 moved the constraint onto `occupancy_range`. Fixed. |
| 6 | `02`'s J3 narrative and NFR-03 vs. D-0008 | INCOMPLETE (confirmed, same finding as #5) | Same stale-mechanism gap Session 5 already flagged and didn't fix (scope). The underlying guarantee held throughout — D-0008 strengthened it, never weakened it. Fixed this session. |

**Endpoints checked for unreachable/meaningless status, not just
inaccuracy:** none are unreachable. Two are affected beyond wording: the
admin endpoint's *mechanism description* is wrong (fixed, see below) and
`/confirm-payment`'s tenant-context mechanism is genuinely unspecified (not
fixed, raised as an open item). No endpoint was found to be obsolete.

**Phase 1 — `05` reconciled**, all via dated Session 6 amendments, not
silent edits:
- Platform-admin endpoint row corrected to D-0009's impersonation
  mechanism (was: stale `BYPASSRLS`-equivalent wording).
- Service-management endpoints (`POST`/`PATCH /api/owner/services`) given
  a real detailed shape for the first time, with D-0012's required
  `buffer_before_minutes`/`buffer_after_minutes` fields. **Written against
  both-configurable (the broader case)** per this session's instruction —
  D-0008's before/after-only scope ruling stays open (see below), flagged
  inline in `05` rather than decided.
- Manual re-invite endpoint (FR-23/D-0014) defined:
  `POST /api/owner/customers/{id}/re-invite`. Surfaced a real, unrelated
  gap while defining it: `04`'s `notification_deliveries.purpose` CHECK
  list has no value for this send type — not fixed (`04` wasn't in this
  session's authorized scope), carried as a proposal below.
- `mandate_accepted`/`mandate_template_version` (D-0015(b)) confirmed
  present and correct — no change needed.
- Webhook table's `payment_intent.succeeded`/`payment_intent.payment_failed`
  rows clarified per finding #1 above.
- `02`'s J3 narrative and NFR-03 rewritten to describe D-0008's
  `occupancy_range` mechanism per findings #5/#6.

**Phase 2 — the missing D-0008 invariant test.** D-0012 (Session 5) found
that D-0008's buffer-snapshot source column had never actually existed in
the schema until that ruling added it — D-0008's core guarantee (a
studio's later buffer-config change never retroactively alters an
already-created booking) was unimplementable as written for four sessions,
undetected, because every DDL execution and test asked whether the schema
was internally consistent, never whether it delivered that specific
property. Added:
- A postscript to D-0012 in `09-decision-log.md` recording this
  observation about the verification itself, not just the fix.
- The actual invariant test in `07-testing-strategy.md` (Concurrency and
  slot integrity): change a service's buffer configuration, assert an
  existing booking's `occupancy_range` is unchanged and a new booking picks
  up the new value — the property stated directly, not inferred from
  adjacent cases.
- A review of `07` against D-0006, D-0009, D-0010 for decisions whose
  stated purpose lacked a corresponding test: D-0009 reviewed clean (the
  tenant-isolation suite already tests the actual fail-closed property, not
  just its mechanism); D-0006 had a clear-cut gap (the balance charge must
  reuse the mandate's exact saved payment method — added directly); D-0010
  had a gap that is **not** clear-cut (whether customer erasure, FR-18,
  touches that customer's `payment_mandates` rows is unspecified in `04` —
  a data-model question, not a test-coverage one) — listed as a proposal,
  not invented as a test.

**Files touched this session:** `02-requirements.md` (J3/NFR-03),
`05-api-contracts.md` (reconciliation, two new detailed endpoints, webhook
clarification), `07-testing-strategy.md` (D-0008 invariant test, D-0006
payment-method test, D-0010 gap noted), `09-decision-log.md` (D-0012
postscript), this file. **Not touched, per this session's constraints:**
`03`, `04`, `06`, `08`, `10`, `11`, `13`, `14` — no application code, no
`composer.json`.

**Commit:** see the repository's commit history for this session's single
commit (`docs: reconcile API contracts with post-Session-2 decisions`);
`git status` is clean after it.

**Proposed but not made this session (listed, not acted on, per this
session's scope-discipline instruction):**
- `POST /api/bookings/{id}/confirm-payment`'s tenant-context derivation
  mechanism is genuinely unspecified by D-0009 — needs a fourth ruling
  (alongside the slug and token mechanisms D-0009 already names) on how
  this public, unauthenticated, unscoped-ID endpoint resolves tenant
  context before its RLS-protected lookup can run.
- `04-data-model.md`'s `notification_deliveries.purpose` CHECK list needs a
  value for the manual re-invite send (e.g. `manual_reinvite`) now that
  `05` defines the endpoint that would create such a row.
- `04-data-model.md` needs to specify whether/how a customer erasure
  (FR-18) interacts with that customer's `payment_mandates` rows
  (`accepted_ip`/`accepted_user_agent` are arguably personal data, and
  D-0010 says the table is "never deleted" for dispute-evidence reasons —
  those two facts aren't yet reconciled anywhere).

**Open items, split pilot-dependent vs. needs-your-ruling (unchanged items
carried forward from Session 5's list are not repeated in full — see that
section above; only new items and status changes are listed here):**
- *Needs your ruling, new this session:* the `/confirm-payment`
  tenant-context mechanism (proposal above) and the two `04` follow-ups
  (proposals above) — none were authorized fixes this session.
- *Still needs your ruling, unchanged:* D-0008's before/after-only buffer
  scope (05's new service-management endpoint is written against
  both-configurable specifically so it doesn't presuppose an answer);
  D-0010's mandate wording/SCA research (D-0015(a)).
- *Still needs a pilot, unchanged:* everything Session 5 already listed
  under that heading (slot-computation materialization, reminder-cadence
  effectiveness, the 15-minute hold window's correctness, `08`'s hosting
  choices).

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

## Amendment (Session 7, 2026-08-24) — confirm-payment tenant-context gap closed, three further rulings recorded and made testable

**Objective:** close the one live, exploitable gap Session 6 found and
deliberately didn't fix (`/confirm-payment`'s missing tenant-context
mechanism — a correctness defect on a money-carrying path, not a
documentation gap), record three further rulings, and write the tests
those rulings make writable.

**Phase 1 — confirm-payment.** Presented the problem precisely (what the
endpoint accepted; what happens under D-0009's fail-closed RLS both with
app-layer scoping intact — a chicken-and-egg deadlock that fails closed for
everyone, not just attackers — and with it bypassed — a real
cross-tenant BOLA risk; and that `appointment_id` is a `uuid`, not
enumerable by brute force, but never designed as a secret in the first
place) and four options (booking-scoped token; derive from the Stripe
PaymentIntent; authenticate the endpoint; remove it and rely on webhooks
alone — the last explicitly checked against `05` endpoint 6's synchronous-
response precedent, not endpoint 3's own, per the session's framing).
Ruled: **(a) booking-scoped signed token.** Recorded as **D-0021**,
amending D-0009: a new `payment_confirmation_token` (distinct from
`manage_token`, least-privilege), the route changed to
`POST /api/bookings/{token}/confirm-payment`, verification-before-context
ordering specified, not single-use, no new storage required. D-0009's
public-path mechanism list is now framed as "slug-based, plus a
signed-token capability class" rather than two fixed, unrelated
mechanisms — closing the gap by generalizing an existing mechanism, not
inventing a third one.

**Phase 2 — three further rulings recorded:**
- **D-0022 (R10):** `payment_mandates` is retained in full after a linked
  customer's erasure, including `accepted_ip`/`accepted_user_agent`
  (classified as dispute-evidence, not identifying — see `04`'s table
  notes for the per-column reasoning), on a legal-claims basis, reconciled
  with `06` as a carve-out, not a conflict. This also closes the specific
  gap `07`'s Session 6 amendment flagged and declined to invent an answer
  for.
- **D-0023 (R11):** `notification_deliveries.purpose` gains
  `rebooking_invite`, distinct from the automatic `rebooking_prompt` — the
  distinct value is what makes D-0014's manual-vs-automatic boundary
  auditable from the data itself.
- **D-0024 (R12):** buffer stays both-configurable (before and after) —
  formally closes an item that had drifted open across three sessions
  waiting for pilot data it never needed; no schema or contract change
  follows, since `04`/`05` were already written against this.

**Phase 3 — tests added to `07-testing-strategy.md`:** confirm-payment
token cases (fails closed on a bad/wrong-purpose/expired token with no
existence leak; a token is scoped to exactly one appointment by
construction; retry/double-submit and post-confirmation idempotency); the
D-0010 erasure test now writable given D-0022 (erase a customer, assert
`customers` PII nulled and `payment_mandates` — including
`accepted_ip`/`accepted_user_agent` — byte-for-byte unchanged, and still
linkable to `booking_events`); and a new Notification coverage section
seeded by the `rebooking_invite` case (D-0023), asserting it's never
conflated with `rebooking_prompt`. Re-checked whether any existing `07`
test asserted a mechanism D-0021 changed: no — the tenant-isolation
suite's cross-tenant-ID-guessing case is scoped to authenticated
owner/staff endpoints only, and no other section referenced
`/confirm-payment`; nothing required invalidation.

**Files touched this session:** `09-decision-log.md` (D-0021 through
D-0024, plus short resolution pointers appended to D-0008 and D-0009),
`05-api-contracts.md` (endpoint 2's response, endpoint 3 redesigned,
public endpoint table row, endpoint 8's buffer-scope flag closed, endpoint
9's `rebooking_invite` gap closed), `04-data-model.md` (`payment_mandates`
erasure-carve-out note, `customers` cross-reference, `notification_
deliveries.purpose` CHECK list, open-questions cleanup — no other schema
change, per D-0021 needing none), `06-security-threat-model.md` (Session 7
amendment reconciling both the confirm-payment fix and the erasure
carve-out), `07-testing-strategy.md` (three new test groups), this file.
**Not touched, per this session's explicit constraints:** `10`, `11`,
`13`, `14`; no application code, no `composer.json`; D-0015(a) (mandate
wording/SCA research) was not decided.

**Proposed but not made this session:** nothing beyond what's already
recorded as deferred in the decisions themselves (D-0015(a) stays
deferred, unchanged) — this session's scope-discipline instruction was
followed throughout; no additional follow-on changes were identified that
weren't either made or explicitly left to a named, still-deferred item
above.

**Open items, split pilot-dependent vs. needs-your-ruling:**
- *Needs a pilot (unchanged from Session 5/6):* slot-computation
  materialization under real traffic; reminder-cadence effectiveness
  (R-04); the 15-minute hold window's actual correctness (D-0011); `08`'s
  hosting-provider/region/tier choice and PITR/retention sizing; concrete
  alerting thresholds.
- *Needs your ruling — only one remains:* D-0010's exact mandate
  wording/copy and whether a formal Stripe SCA mandate flow is required
  for a given card scheme/region (D-0015(a)) — legal-adjacent
  implementation-session research, explicitly out of this session's scope
  to decide. Every other "needs your ruling" item Session 6 carried
  forward (the confirm-payment mechanism, the `notification_deliveries`
  re-invite value, the erasure/`payment_mandates` interaction, and the
  buffer before/after scope) is now resolved by this session's four
  rulings.

**Commit:** see the repository's commit history for this session's single
commit; `git status` is clean after it.

**Session 6** closed the two named `05` gaps from Session 5's audit, redid
that audit completely (it had silently omitted two required checks), and
added the D-0008 invariant test that four sessions of otherwise-passing
verification never actually exercised. The redone audit reported every
check with an explicit verdict, not just the failures: five of six were
CLEAN or already-known-and-fixed; the two live gaps were `05`'s stale
`BYPASSRLS`-equivalent admin wording (fixed, now describes D-0009's
impersonation mechanism) and `02`'s J3/NFR-03 still citing D-0007 alone
instead of D-0008's `occupancy_range` mechanism (fixed). `05` also gained,
for the first time, a real detailed shape for service creation (D-0012's
required buffer fields, written against both-configurable since the
before/after-only scope ruling is still open) and a defined manual
re-invite endpoint (D-0014/FR-23). One genuinely new, unresolved gap
surfaced and was **not** fixed, per this session's instruction not to
invent missing detail in a later decision: `POST
/api/bookings/{id}/confirm-payment` is public and unauthenticated but has
neither a `{slug}` nor a signed token to derive tenant context from before
its RLS-protected lookup — D-0009 names exactly two public-path mechanisms
and this endpoint uses neither. For Phase 2, D-0012's postscript in `09`
records a verification-methodology observation (four sessions of
schema-consistency tests never checked whether D-0008's actual guarantee
held), `07` gained the direct invariant test (change a buffer config, prove
existing bookings are unaffected and new ones pick up the change) plus a
reviewed-and-added D-0006 gap (the balance charge must reuse the mandate's
exact saved payment method) — and a reviewed-but-not-invented D-0010 gap
(customer erasure's interaction with `payment_mandates` retention is
unspecified in `04`, a data-model question left as a proposal). Files
touched: `02`, `05`, `07`, `09`, this file — `03`/`04`/`06`/`08`/`10`/`11`/
`13`/`14` untouched, no application code. Three items now need a ruling
that didn't before: the `/confirm-payment` tenant-context mechanism, a
`notification_deliveries.purpose` value for the re-invite send, and the
erasure/`payment_mandates` interaction — see this file's Session 6
amendment above for all three as proposals, not decisions.

**Session 7** closed all three of those proposals plus one more the
handoff had carried since Session 3, as four rulings. The confirm-payment
gap (a real correctness defect: the endpoint was public, unauthenticated,
and had no way to derive tenant context before an RLS-protected lookup —
D-0009 named exactly two public-path mechanisms and this endpoint used
neither) was closed as **D-0021**: a new booking-scoped, purpose-scoped
signed `payment_confirmation_token` (distinct from `manage_token`)
replaces the raw `appointment_id` in the endpoint's route, generalizing
D-0009's existing token mechanism rather than adding an unrelated third
one — no new storage needed. Three further rulings: **D-0022** — customer
erasure never touches `payment_mandates` (including `accepted_ip`/
`accepted_user_agent`, classified as dispute-evidence, not identifying),
retained on a legal-claims basis, reconciled with `06` as a carve-out;
**D-0023** — `notification_deliveries.purpose` gains `rebooking_invite`,
kept distinct from the automatic `rebooking_prompt` so the audit trail can
always tell a manual re-invite from a system one; **D-0024** — buffer
stays both-configurable, formally closing an item that had drifted open
across three sessions for no real reason (a config knob, not a validated
policy). All three test cases these rulings make writable were added to
`07` (confirm-payment token cases in the tenant-isolation suite; the
now-writable D-0010 erasure test; a new `rebooking_invite` case), and `07`
was re-checked for any test asserting a mechanism D-0021 changed — none
found. Files touched: `09` (D-0021-D-0024), `05`, `04`, `06`, `07`, this
file — `10`/`11`/`13`/`14` untouched, no application code, D-0015(a)
(mandate wording/SCA research) still deliberately deferred. The only
"needs your ruling" item left standing after this session is D-0015(a)
itself; everything else is now either resolved or pilot-dependent — see
this file's Session 7 amendment above for the full open-items split.

## Amendment (Session 8, 2026-08-24) — first implementation session: scaffold, migrations, tenant-context plumbing, tenant-isolation suite

**Objective:** the first session to write actual application code. Scaffold
Laravel, stand up `composer ci:check`, translate `04`'s DDL into real
migrations, build the smallest real tenant-context mechanism, and implement
`07`'s tenant-isolation suite. Explicitly out of scope: controllers, routes,
endpoints, Stripe integration, notification jobs, availability computation,
the booking flow, or any frontend work — all confirmed untouched.

**Phase 1 — scaffold and gate.** Laravel 13 (PHP 8.4) scaffolded at the repo
root, API-only (no Blade/Inertia — `routes/web.php` carries no routes; no
`resources/views`), matching `03`'s decoupled-architecture direction.
`composer.json` gained `ci:check` (→ `pint --test` then `phpstan analyse`
via Larastan level 5 then `pest --exclude-group=slow`) plus
`test:unit`/`test:feature`/`test:tenant-isolation`/`test:slow` scripts.
**Verified: `composer ci:check` exits 0** — Pint clean, PHPStan 0 errors,
16/16 Pest tests passing, 150 assertions, ~7s wall-clock for the whole gate.
Local Postgres 17 + Redis via `docker-compose.yml`
(`docker/postgres/init/01-roles-and-database.sql` creates both database
roles automatically on first volume init). Both D-0009 roles exist and are
genuinely separate credentials in `.env.example`/`config/database.php`:
`bookslot_app` (connection `pgsql`, the only runtime role) and
`bookslot_migrator` (connection `pgsql_migrator`, holds `BYPASSRLS`, used
only for `--database=pgsql_migrator` migration runs — confirmed via a live
query that `bookslot_app`'s `rolbypassrls` is `false` and
`bookslot_migrator`'s is `true`). Per D-0020, `bookslot_migrator`'s
credential lives only in local `.env`/CI-irrelevant config, never wired into
any automated non-migration path.

**Phase 2 — migrations.** All 18 migrations translate `04`'s DDL faithfully,
in `04`'s exact order (extension → function → tables → exclusion constraint
→ RLS), using `DB::unprepared()` raw SQL throughout — Laravel's schema
builder cannot express `tstzrange`, `GENERATED ALWAYS AS ... STORED`,
`EXCLUDE USING gist`, or RLS policies. Every migration has a working
`down()`. **Confirmed by execution: migrate → rollback (all 18, clean) →
re-migrate (all 18, clean) from an empty database**, run against real
PostgreSQL 17.11. `occupancy_window()` carries `SET search_path = pg_catalog,
pg_temp` inline per D-0008; `occupancy_range` is a STORED generated column;
buffer columns are `NOT NULL` with no default and the `[0,1440]` CHECKs; the
containment CHECK and the tenant+staff-scoped partial `EXCLUDE USING gist`
on `occupancy_range` are both present, verified via `\d appointments`.
`payment_mandates` carries the full D-0022 erasure carve-out column set;
`notification_deliveries.purpose` includes `rebooking_invite` (D-0023). RLS
is enabled + forced on all 12 manifest tables (`config('tenancy.
tenant_scoped_tables')`), each with a `tenant_isolation` policy — `tenants`
and `stripe_webhook_events` correctly carry neither, confirmed via
`pg_class`/`pg_policies` introspection.
**One genuine gap found only by executing the DDL, not by reading it — see
`09-decision-log.md` D-0025 and `04`'s new Session 8 amendment:** D-0005/`04`
claim `current_setting(..., true)` returns `NULL` when the tenant GUC is
unset; verified against real Postgres that this is only true for a
connection that has *never* set it — once set even once (even after a real
`COMMIT`), the same connection gets `''` (empty string) thereafter, which
raises `SQLSTATE 22P02` on the `::uuid` cast instead of comparing as `NULL`.
Under this project's own chosen PgBouncer transaction-mode pooling, that's
the normal state on every backend connection after its first tenant-scoped
transaction. Presented to the user as a real divergence between documented
and actual behavior, per this session's instruction to stop and report
rather than silently patch; the user chose to fix the policy expression now.
Every `tenant_isolation` policy now reads `tenant_id = NULLIF(current_
setting(...), '')::uuid`, verified directly against Postgres both before
and after the change.

**Phase 3 — tenant-context plumbing (the smallest real mechanism, not
route-driven middleware for routes that don't exist).** `App\Tenancy\
TenantContext::run($tenantId, $callback)` opens a transaction, sets the GUC
via parameterized `set_config(..., true)` as its first statement, then
restores the prior app-layer tenant on exit — the single, only mechanism
used anywhere. `App\Tenancy\CurrentTenant` is the process-local holder the
app-layer scope reads. `App\Models\Scopes\TenantScope` (and `UserTenantScope`
for `users`' platform-admin bypass) implement D-0005's first enforcement
layer, applied via `App\Models\Concerns\BelongsToTenant` on every
tenant-scoped Eloquent model — fail-closed at the app layer too (no context
set → `1=0`, mirroring RLS's own fail-closed design). `App\Jobs\
TenantScopedJob` requires `tenant_id` in its constructor (refuses
construction/dispatch without one) and re-sets the GUC via job middleware
(`App\Jobs\Middleware\SetsTenantContext`) on every execution attempt,
including retries; `App\Jobs\PlatformJob` is the tenant-less base for
housekeeping. `App\Http\Middleware\SetTenantContext` exists and is
registered as a named alias (`tenant.context`) but attached to no route —
real request-level tenant *resolution* (slug lookup, auth, D-0021's signed
token) is explicitly next-session work, noted below.

**Phase 4 — the tenant-isolation suite.** `tests/TenantIsolation/` (16
tests, 150 assertions) covers every bullet this session was scoped to,
against real Postgres, no SQLite anywhere:
- **Manifest completeness + RLS enforcement**
  (`RlsManifestTest.php`): introspects `information_schema.columns`/
  `pg_class`/`pg_policies` against `config('tenancy.tenant_scoped_tables')` —
  parameterized over the live schema, not hand-maintained per table.
  **Demonstrated actually failing, not just written:** added a throwaway
  `unprotected_demo_table` (tenant-scoped, no RLS) via a temporary migration,
  ran the suite, watched the manifest-completeness test fail with an exact
  diff naming the new table, then deleted the migration and confirmed the
  suite passes clean again. This is the guard's whole value — verified, not
  assumed.
- **Global scope bypassed** (`GlobalScopeBypassTest.php`): raw `DB::select`,
  the query builder (`DB::table`), and a raw `DB::statement` INSERT attempt
  claiming a different tenant (rejected by the policy's `WITH CHECK`, which
  defaults to the `USING` expression) — all three never touch Eloquent.
  Includes the fail-closed-with-no-context case (using `TenantContext::
  clear()`, added specifically because a nested `TenantContext::run()` call
  under Pest's `RefreshDatabase`-wrapped transaction only opens a savepoint,
  which — per the D-0025 finding — doesn't reset the GUC the way a real
  `COMMIT` would need to anyway).
- **Eager loading and relationship traversal** (`EagerLoadingTest.php`):
  `Appointment::with('staff','service','customer')` never resolves a
  different tenant's row, whether via RLS filtering the relation query or
  the base row itself being invisible under the wrong context.
- **Cross-tenant ID access at the model layer** (`CrossTenantModelAccessTest.
  php`, replacing 07's endpoint-based version since no controllers exist):
  every manifest table's model refuses to resolve another tenant's real row
  by id; a real (not guessed) staff id belonging to a different tenant, used
  as a foreign key while under the wrong context, still resolves to `null`
  on read — the FK constraint itself doesn't stop this (Postgres documents
  that referential-integrity checks bypass RLS), so this is a genuine test
  of the read-time backstop, not a redundant one.
- **Queue jobs without tenant context; jobs retried after a context change**
  (`QueueJobTenantContextTest.php`): constructing a `TenantScopedJob`
  subclass with an empty tenant id throws; `dispatchSync()` (which, with
  `QUEUE_CONNECTION=sync`, does run the real job-middleware pipeline —
  confirmed by reading Laravel's own dispatcher source, not assumed) sets
  the GUC correctly for the job's own tenant; a tenant-A job that "retries"
  after a tenant-B job ran on the same simulated worker still observes
  tenant A, never B.
- **`BYPASSRLS` unreachable from application runtime**
  (`BypassRlsUnreachableTest.php`): the app's configured runtime username is
  `bookslot_app`, not `bookslot_migrator`; a live query on the runtime
  connection confirms `rolbypassrls = false`; a live query on the migrator
  connection confirms the opposite, so the first assertion is proven to
  actually discriminate rather than passing by coincidence.

**Actual runtime against the 60-second budget (D-0017): the tenant-isolation
suite alone (`composer test:tenant-isolation`) runs in ~3 seconds** — the
schema-setup migration (also via `bookslot_migrator`, ~1 second) plus Pest
itself. Far under budget.

**Phase 5 — commit.** `.gitignore` updated: `.env.testing` is explicitly
un-ignored (it carries no real secrets — fixed local-only Postgres
passwords matching `docker/postgres/init/`, needed for `composer ci:check`
to work identically on every clone/CI run). Confirmed via `git status`/
`git add -n` that no `.env`, `vendor/`, or generated cache/log file
(`bootstrap/cache/*.php`, `storage/logs/*`, `storage/framework/testing/
_pest.php`) is staged. Single commit; see the repository's commit history
for its SHA — `git status` is clean after it.

**Files touched this session:** every file under `app/`, `bootstrap/`,
`config/`, `database/`, `docker/`, `public/`, `routes/`, `storage/`,
`tests/` (all new), `artisan`, `composer.json`/`composer.lock`,
`docker-compose.yml`, `phpunit.xml`, `phpstan.neon.dist`, `.env.example`,
`.env.testing`, `.editorconfig`, `.gitattributes`, `.gitignore` (amended),
`README.md`/`CONTRIBUTING.md` (setup instructions filled in). Project-memory
files touched: `09-decision-log.md` (D-0025), `04-data-model.md` (Session 8
amendment reflecting D-0025 and noting migrations now exist),
`08-deployment-and-operations.md` (Session 8 amendment: `ci:check`'s real
contents against its own prediction), this file. **Not touched, per this
session's explicit constraints:** `02`, `03`, `05`, `06`, `10`, `11`, `13`,
`14` — nothing this session did contradicted or needed to amend any of them.

**What turned out to need a ruling mid-session (not silently decided):**
the D-0025 RLS fail-closed gap above — presented with two options (harden
the policy expression now, vs. leave the DDL as-is and document the gap for
a future session), user chose to harden it now. No other documented
decision was found to be wrong or unimplementable as written this session —
D-0007/D-0008's exclusion-constraint DDL, the RLS role split, the buffer/
occupancy-range mechanics, and the manifest-driven test design all executed
exactly as `04`/`07`/`09` describe.

**What this session deliberately did NOT build, and why:**
- No `tests/Unit` or `tests/Feature` content — no pure-logic code (deposit
  calculation, buffer arithmetic) or controllers/endpoints exist yet for
  either layer to exercise. The composer scripts and test-suite
  registrations already exist, pointed at currently-empty directories.
- No `tests/` coverage for `07`'s "Concurrency and slot integrity" section
  (true multi-connection exclusion-constraint races, `'[)'` boundary cases,
  DST-pinned fixtures) — that section is about D-0007/D-0008 correctness
  under concurrency, a different (and, per `07` itself, slower/nightly-tier)
  concern from tenant isolation, and this session's Phase 4 instruction
  named exactly the tenant-isolation bullets above, not that section.
- No HTTP-level tenant resolution (slug lookup, auth, D-0021's signed
  token), no controllers, no routes, no Stripe, no frontend — all
  explicitly out of this session's scope per its own hard boundary.

## Next recommended session

- Proposed session title: **Session 9 — Real Tenant Resolution and the
  Booking-Creation Path, or a Real Pilot Discovery.**
- As in every prior handoff: a real candidate pilot studio becoming
  available should take priority over further build work — R-01 remains the
  standing top risk in `10-risk-register.md`, now untouched by eight
  sessions in a row (two build, six design). Absent that, the natural next
  build-side session is wiring `App\Http\Middleware\SetTenantContext` into
  an actual request lifecycle: the slug-based public-booking resolution and
  the authenticated owner/staff path (D-0009's two named public-path
  mechanisms, plus D-0021's signed-token class), which unblocks writing the
  first real controllers/routes and, with them, the first Feature-layer
  tests `07` describes. `04`'s Availability modeling section ("derived, not
  materialized") and the booking-creation path (J1–J3) are the concrete
  first slice once resolution exists — that's also what would finally
  exercise `07`'s Concurrency and slot integrity section for real.
- Inputs required: this Project Memory Pack, particularly `05-api-contracts.
  md` for the endpoint shapes already specified, `09` D-0009/D-0021 for the
  exact tenant-resolution mechanisms to implement, and `07`'s Concurrency
  and slot integrity section for the true-multi-connection test pattern
  once booking creation exists to test.
- Definition of done: at minimum, the public booking-page slug resolution
  path wired end-to-end (middleware → real tenant lookup → RLS-protected
  read), with a Feature test proving it, and this handoff file updated with
  the new session's amendment.
