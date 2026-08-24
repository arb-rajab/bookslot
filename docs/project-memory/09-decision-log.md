# Decision Log
> Purpose: why things are the way they are, so decisions are not silently undone.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-24 (Session 4 — D-0008 DDL Correction After Execution Testing)

This is a private repository — full ADR ceremony (separate `docs/adr/`
files per decision, as used in the public flagships) is not required here.
Lightweight inline entries are enough, per this developer's own judgment
call for private-track work.

## D-0001 — Reuse Laravel + PostgreSQL, deliberately, despite this being a repeated stack

- **Date:** 2026-08-23 · **Status:** accepted
- **Context:** the public portfolio track has a governance rule pushing
  against repeating a primary backend/database across public repositories,
  to demonstrate technical range to a reviewer. This repository is private
  commercial work with no such reviewer audience, and that rule explicitly
  does not apply to private repositories.
- **Decision:** use Laravel (PHP) as the backend and PostgreSQL as the
  database, matching this developer's other proven work.
- **Why (business fitness, not laziness):** this targets a price-sensitive
  market (see `00-project-brief.md`) where delivery speed and low ongoing
  operating cost decide commercial viability. Spending build time learning
  an unfamiliar stack for this specific product would misallocate scarce
  effort away from the parts of this product that are genuinely novel and
  risky (Stripe Connect, multi-tenancy) and toward re-deriving skills
  already held. PostgreSQL is additionally justified on its own technical
  merits independent of reuse — its exclusion-constraint mechanism is a
  strong fit for the double-booking-prevention problem (see
  `03-architecture.md`).
- **Must not be silently reversed because:** if a future session proposes
  switching stacks "for variety" or "to try something new," that is
  optimizing for the wrong incentive — this is not a public portfolio piece
  being judged on range. A real reason (e.g., a genuine technical
  limitation discovered during implementation) would be needed to revisit
  this.

## D-0002 — Nuxt frontend, decoupled from the Laravel API (not Inertia)

- **Date:** 2026-08-23 · **Status:** accepted
- **Context:** this developer's other Laravel work (`privacy-forge`) uses
  Vue via Inertia — a tightly coupled, server-driven frontend pattern well
  suited to an authenticated internal-tool-style UI.
- **Decision:** use a decoupled Nuxt (Vue) frontend calling a Laravel JSON
  API, not Inertia.
- **Why:** the public-facing booking page is this product's most SEO- and
  performance-sensitive surface (reached via social/business-profile links,
  on mobile data, by a customer likely to bounce if it's slow) — a use case
  Inertia is not designed around the way Nuxt's SSR is. A decoupled API
  also avoids locking a future mobile app (a plausible paid-expansion item)
  into an Inertia-shaped backend.
- **Trade-offs accepted:** more upfront integration work (API auth, CORS,
  a real versioned contract) than Inertia's same-process convenience.
  Accepted because the SSR/SEO need is concrete for this specific product.
- **Must not be silently reversed because:** switching back to Inertia
  later would mean re-doing the public booking page's rendering model —
  worth deciding once, deliberately, now.

## D-0003 — Stripe Connect (Express), not plain Stripe payments

- **Date:** 2026-08-23 · **Status:** accepted
- **Context:** this product is a platform facilitating payment from a
  business's customer to that business, taking a platform fee — not a
  merchant selling its own goods.
- **Decision:** Stripe Connect, Express account type, for connected
  (tenant) businesses.
- **Why:** Connect is Stripe's product for exactly this platform-fee model;
  plain Stripe assumes the platform is the merchant of record. Express
  (over Standard/Custom) trades some white-labeling for Stripe-hosted KYC/
  onboarding UI, minimizing integration burden for a self-serve,
  non-technical small-business owner — the right trade-off at MVP.
- **Must not be silently reversed because:** switching account types later
  (e.g., to Custom for more branding control) means re-doing onboarding and
  potentially re-onboarding existing connected accounts — a real migration,
  not a config change.

## D-0005 — Tenant isolation: row-level `tenant_id`, enforced at both the application layer and via Postgres RLS

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `03-architecture.md`'s original direction was row-level `tenant_id` scoping enforced only via Laravel ORM global scopes. `06-security-threat-model.md` flags multi-tenancy as the one genuinely new architectural risk relative to this developer's other work (`privacy-forge` is single-org). App-scope-only enforcement means a raw query, a forgotten global scope, or a queued job that runs without tenant context set is a full cross-tenant data leak with no second line of defense.
- **Options considered:**
  - Row-level `tenant_id`, app-layer scoping only — simplest, but the failure mode above has unbounded blast radius (a single missed scope leaks every tenant's rows in that table).
  - **Row-level `tenant_id`, app-layer scoping + Postgres RLS backstop — chosen.**
  - Schema-per-tenant — rejected: migration ergonomics scale linearly with tenant count (every migration runs N times, schema-drift risk), cross-tenant/platform-admin reporting becomes cross-schema queries, and Laravel tooling for it (e.g. stancl/tenancy) adds real operational complexity disproportionate to a segment of many *small* (1–6 chair) tenants. Would win if the trajectory shifted to few, large, high-ACV tenants needing contractual isolation guarantees — 01's segment boundary explicitly excludes that for MVP.
  - Database-per-tenant — rejected: multiplies connection management, migration orchestration, and backup fleet per tenant with no stated compliance/data-residency requirement to justify it.
- **Decision:** every tenant-scoped table carries a `tenant_id` FK. Enforcement is two independent layers: (1) Laravel global scopes on every Eloquent model, and (2) Postgres Row-Level Security policies on every tenant-scoped table with `FORCE ROW LEVEL SECURITY`, keyed on a session GUC (`app.current_tenant_id`) set by request middleware and at the start of every queued job. If the GUC is unset, RLS matches zero rows — fail closed, not fail open. Migrations run under a separate DB role that is not subject to RLS (RLS binds the app's runtime role only). Cross-tenant/platform-admin queries go through an explicit, narrow admin role/policy (e.g. `BYPASSRLS`), not an app-code path that casually skips scopes.
- **Why:** this caps the blast radius of the entire "forgot to scope a query" bug class from "all tenants' data in that table" down to "zero rows," at the database layer, regardless of which code path issued the query — directly answering 06's flag that this is the one area where "we've done this before" doesn't apply.
- **Note on GDPR erasure (06):** 06's erasure requirement applies to a studio's *end customer*, not the tenant/studio itself — so it's a row-level concern independent of which tenancy boundary wins here. This decision doesn't change how erasure is handled; see `04-data-model.md`.
- **Must not be silently reversed because:** dropping the RLS layer back to app-scope-only would silently remove the fail-closed backstop that is the whole point of this decision — see the amendment in `03-architecture.md`.

## D-0006 — Deposit mechanics: immediate-capture PaymentIntent with saved card for the balance charge

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** three realistic mechanics exist for collecting a deposit and later a balance via Stripe Connect. This vertical's bookings are typically made weeks in advance (00's stated value proposition rests on this lead time enabling a multi-touchpoint reminder cadence).
- **Options considered:**
  - **Immediate-capture PaymentIntent at booking time, with `setup_future_usage: off_session` to save the card for the later balance charge; disposition (forfeit/apply/refund) is a later explicit action on already-captured funds — chosen.**
  - Manual-capture PaymentIntent, held and captured/cancelled at appointment time — rejected: Stripe's manual-capture authorization holds expire (commonly around 7 days, varies by network/issuer), far short of this vertical's typical multi-week booking lead time. Most real bookings would outlive the hold before the appointment happens. Would win in a shorter-lead-time vertical (e.g. short-notice bookings) — out of scope, since we're not building for verticals not yet committed to.
  - SetupIntent saving a card, charged only on no-show — rejected: no money moves at booking time, which contradicts 00's stated mechanic ("pays a deposit... so my slot is held") and removes the up-front skin-in-the-game effect the business case (R-04) credits for reducing no-shows. Also risks an off-session SCA/3-D-Secure decline at the exact moment — weeks later, no cardholder present — the studio is trying to enforce the no-show penalty. Would win only under a deliberate product repositioning of "deposit" as a pure no-show penalty rather than an upfront payment — a business decision, not an engineering one, and contradicts 00 as currently written.
- **Decision:** deposit is captured immediately at booking via a Stripe Connect destination charge (`application_fee_amount` for the platform's cut). The same PaymentIntent saves the payment method for later off-session use. Disposition after the appointment is bookkeeping on already-moved funds: attended → applied to balance (no further Stripe call); no-show → forfeited per studio policy (no further Stripe call); cancelled within a studio-configured window → an explicit refund (partial or full) of the captured charge.
- **Booking vs. payment state:** modeled as two loosely-coupled state machines (booking lifecycle; payment status per payment row), not one merged machine — a booking can be cancelled after its deposit is captured (refund is a decision independent of booking status), and a payment can be mid-flight (e.g. awaiting 3DS) independent of the booking's own lifecycle. See `04-data-model.md`.
- **Consequences to disclose (open, not yet resolved):** destination charges assign dispute/chargeback liability to the connected account (the studio) by default — correct here since the studio is merchant of record, but studio-onboarding copy (a future session) needs to disclose this explicitly rather than let it surface as a surprise at first dispute. Statement descriptor (what the customer's card statement shows) is a per-connected-account Stripe setting — open product question, not a schema blocker.
- **Must not be silently reversed because:** switching to manual-capture later would require re-deriving the hold-expiry-vs-lead-time tradeoff above; switching to SetupIntent-only would be a real repositioning of the product's deposit value proposition, not a technical tweak.

## D-0007 — Double-booking exclusion constraint: tenant+resource scoped, partial, buffer excluded from the stored range

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `03-architecture.md` recorded the `tstzrange` + `EXCLUDE USING gist` direction but not its specifics.
- **Decision:**
  - `btree_gist` extension created in its own early migration, before any migration adding the exclusion constraint (GIST has no native `=` operator support for scalar types like `uuid` without it).
  - Constraint scoped by both `tenant_id` and `resource_id` (equality) and `appointment_range` (overlap `&&`), with a **partial** `WHERE (status NOT IN ('cancelled', 'no_show') AND deleted_at IS NULL)` — without this, a cancelled appointment's row would permanently block that slot from ever being rebooked.
  - Range bounds convention: `'[)'` (half-open), Postgres's default for `tstzrange` — a 2–3pm slot and a 3–4pm slot must not be treated as overlapping. Ranges constructed explicitly with `tstzrange(start, end, '[)')`, plus `CHECK (lower(appointment_range) < upper(appointment_range))` since `tstzrange` permits empty/backwards ranges.
  - Buffer/turnaround time is **not** baked into the stored range — the stored range is the literal appointment window only. Buffer is enforced at slot-*generation* time (computing bookable slots excludes a margin around existing appointments, per service/studio configuration), because buffer requirements are a variable business rule, not the one hard invariant (zero literal overlap) the database must guarantee.
  - Timezone/DST: `timestamptz` stores absolute instants, so the constraint's overlap comparison is DST-proof by construction. DST sensitivity exists only upstream, converting a studio-local wall-clock time to an absolute range at booking-creation time — must use the studio's stored IANA timezone with zone-aware arithmetic, not fixed-offset math.
  - Constraint-violation error surfacing: an exclusion violation raises SQLSTATE `23P01`. Booking-creation code must catch this specific code (not a generic exception catch-all) at a single chokepoint and translate it to HTTP 409 with a machine-readable code (`SLOT_ALREADY_BOOKED`), never a 500. Required concurrency test case: two simultaneous booking attempts on the same slot — one 201, one clean 409.
- **Rejected alternative:** baking buffer into the stored range — rejected because it would make `appointment_range` misrepresent the appointment's actual duration on the calendar, and buffer policy can change per service without needing different range semantics.
- **Must not be silently reversed because:** removing the partial `WHERE` clause would make cancelled slots permanently unbookable; changing the bounds convention without updating all range construction call sites would silently reintroduce boundary-adjacent double-booking bugs.

## D-0008 — Buffer enforcement moved into the exclusion constraint via a second, generated occupancy range

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** Session 3 review of D-0007 found that buffer/turnaround time, as specified ("enforced at slot-generation time... not baked into the stored range"), is unenforceable under concurrency. Slot generation is a read. The EXCLUDE constraint is the only serialization point, and it compares `appointment_range` (the literal window) with `&&`. Two concurrent requests can each compute available slots (correctly excluding a buffer margin) before either commits, then insert two appointments whose literal ranges are disjoint but whose *buffered* windows overlap — the constraint has no way to see this, because the buffer requirement was never expressed in the ranges it compares.
- **Options considered:**
  - **A second, generated `occupancy_range` column carrying the EXCLUDE constraint, with `appointment_range` staying the literal, customer-facing window — chosen.**
  - Extend `appointment_range` itself to include buffer, subtracting buffer at presentation time — rejected: this is the exact alternative D-0007 already rejected, for the same reason (`appointment_range` would misrepresent the appointment's actual duration on the calendar). Reviving it would also mean every consumer of the range (confirmation emails, calendar exports, dashboard) must remember to subtract buffer; one omission leaks buffer time into what the customer is told is their appointment.
  - Accept the race explicitly, unresolved — rejected: it is a real, if narrow, correctness gap (two customers claiming adjacent slots within the same short window) with a concrete bad outcome (an artist with no cleanup time between clients), for the one property (`03`'s "one correctness property this product cannot get wrong") the product is explicitly built around. The fix costs the same engineering effort as documenting why it's left open.
- **Decision:**
  - `appointments` gains `buffer_before_minutes integer NOT NULL DEFAULT 0` and `buffer_after_minutes integer NOT NULL DEFAULT 0`, **snapshotted from the service/studio's buffer configuration at booking-creation time** — not looked up live, and not stored as a single "buffer_minutes" value, so a studio's later buffer-config change never retroactively alters an already-created booking's occupancy window.
  - A new generated column:
    ```sql
    occupancy_range tstzrange GENERATED ALWAYS AS (
      tstzrange(
        lower(appointment_range) - (buffer_before_minutes || ' minutes')::interval,
        upper(appointment_range) + (buffer_after_minutes || ' minutes')::interval,
        '[)'
      )
    ) STORED
    ```
  - The exclusion constraint from D-0007 moves from `appointment_range WITH &&` to `occupancy_range WITH &&`, keeping the same `tenant_id WITH =`, `staff_id WITH =`, and partial `WHERE (status NOT IN ('cancelled','no_show'))` clause unchanged.
  - `appointment_range` is untouched and remains what the customer sees (confirmation, calendar, dashboard) — buffer stays entirely invisible to the customer-facing surface, exactly as D-0007 intended.
- **Why this resolves the race:** the constraint now sees the same buffered window both requests would have separately computed at read time, and enforces it transactionally at insert time — the same mechanism, and the same guarantee, D-0007 already established for literal overlap, extended to cover buffer.
- **Consequences addressed:**
  - **Variable per-artist/per-service buffer:** correct by construction — each row's constraint check uses that row's own snapshotted buffer, not a shared or live value (a generated column couldn't reference a live config table even if this weren't otherwise desirable).
  - **Studio changes its buffer with future bookings already on the books:** existing rows keep their originally-snapshotted buffer; only bookings created after the change get the new one. This is deliberate grandfathering, not a bug — no backfill/migration of existing appointments is needed or intended when a studio edits its buffer setting.
  - **Legitimate back-to-back bookings:** unaffected — a service/studio configured with `buffer_before_minutes = buffer_after_minutes = 0` makes `occupancy_range` identical to `appointment_range`, reproducing today's zero-buffer behavior exactly.
- **Open product question (not answered here):** whether MVP defaults buffer to "after only" (cleanup time following a service) versus allowing both before and after per service — the schema supports either; the default value is a product decision for a future session, not fabricated here.
- **Must not be silently reversed because:** removing `occupancy_range` and reverting the constraint to `appointment_range` would silently reopen this exact race; changing which column carries the buffer snapshot (e.g., looking it up live instead of storing it on the row) would break the grandfathering guarantee and could make the generated column non-deterministic.

### Amendment (Session 4, 2026-08-24) — DDL corrected after execution testing: generated-column immutability failure, hardened function, containment CHECK

Session 3's DDL above was written but never executed. Executing it against PostgreSQL 17.11 fails:

```sql
ALTER TABLE appointments ADD COLUMN occupancy_range tstzrange
  GENERATED ALWAYS AS (
    tstzrange(
      lower(appointment_range) - (buffer_before_minutes || ' minutes')::interval,
      upper(appointment_range) + (buffer_after_minutes || ' minutes')::interval,
      '[)'
    )
  ) STORED;
```
```
ERROR:  generation expression is not immutable
SQLSTATE: 42P17
```
Postgres's immutability checker cannot prove the `integer || text` concatenation and `::interval` cast chain is immutable — even though it is, in this case, since none of the inputs are `now()`- or session-dependent — and refuses to create the column outright rather than merely warning.

**Options considered to fix it:**
- (a) **Trigger-maintained column** — a `BEFORE INSERT OR UPDATE` trigger computes and sets `occupancy_range` — rejected: this reintroduces the exact "did the write path actually run the trigger" trust problem generated columns exist to eliminate. A true generated column cannot be independently written by any INSERT/UPDATE; a trigger-set column can be silently bypassed or desynced by any write path that doesn't expect it, with no database-level guarantee catching the mismatch.
- (b) **A narrow, explicitly `IMMUTABLE`-marked SQL function wrapping the same arithmetic, referenced from the generated column expression — chosen.** `occupancy_window(appt tstzrange, buf_before integer, buf_after integer) RETURNS tstzrange`, declared `IMMUTABLE PARALLEL SAFE`, sidesteps Postgres's inability to *prove* immutability of the inline expression by having the function's author assert it directly — a legitimate assertion here, since the function's actual behavior never touches `now()`, wall-clock/session state, or anything else genuinely non-immutable.
- (c) **Application-layer population** — compute `occupancy_range` in Laravel and pass it explicitly on every insert — rejected: this is D-0008's originally-rejected shape by another name. The entire point of `occupancy_range` is to make the buffered window a database-enforced invariant rather than an application-remembered one; moving its computation to the app reopens the "did every write path remember to compute it correctly" trust gap generated columns were chosen specifically to close.

**Executed proof, PostgreSQL 17.11, scratch database.** The chosen function, as actually defined and confirmed via `\sf occupancy_window`:
```sql
CREATE OR REPLACE FUNCTION public.occupancy_window(appt tstzrange, buf_before integer, buf_after integer)
 RETURNS tstzrange
 LANGUAGE sql
 IMMUTABLE PARALLEL SAFE
 SET search_path TO 'pg_catalog', 'pg_temp'
AS $function$
  SELECT tstzrange(
    lower(appt) - make_interval(mins => buf_before),
    upper(appt) + make_interval(mins => buf_after),
    '[)'
  )
$function$
```
`occupancy_range tstzrange GENERATED ALWAYS AS (occupancy_window(appointment_range, buffer_before_minutes, buffer_after_minutes)) STORED` is accepted without error.

**V1 — containment CHECK, executed this session:**
```sql
ALTER TABLE appointments
  ADD CONSTRAINT appointments_occupancy_contains_appt
  CHECK (occupancy_range @> appointment_range);
```
→ `ALTER TABLE` (accepted — a CHECK constraint may reference a stored generated column at table-alteration time, same as any other column). Proven to bite: with the buffer CHECKs temporarily dropped and `buffer_before_minutes = -5` inserted (the same non-inverting case flagged as a real gap above),
```
ERROR:  new row for relation "appointments" violates check constraint "appointments_occupancy_contains_appt"
```
This CHECK is now part of the DDL in `04-data-model.md`; the buffer-bounds CHECKs remain in place alongside it — see the redundancy note there.

**V2 — inline `SET search_path`, executed this session:** the function above carries `SET search_path TO 'pg_catalog', 'pg_temp'` as part of its `CREATE FUNCTION` statement itself, not applied after the fact via a separate `ALTER FUNCTION` (which is how the previous session's hardening was demonstrated). Under a hostile session setting that explicitly repositions `pg_catalog` (`SET search_path = evil, public, pg_catalog`, with `evil.make_interval(integer)` shadowing the real one), a twin function defined identically but *without* the inline `SET search_path` clause resolves `make_interval` to the hostile schema's version and returns a garbage multi-month range (`["2026-07-21 19:00:00+00","2026-10-13 02:00:00+00")` for a 1-hour appointment with a 5-minute buffer); the production `occupancy_window`, and a freshly-built inline-hardened twin run side by side for direct comparison, both continue to return the correct `["2026-09-01 09:55:00+00","2026-09-01 11:05:00+00")`. The inline form is confirmed equivalent to the previously-demonstrated `ALTER FUNCTION ... SET search_path` form. `04`'s DDL now carries the hardening inline at `CREATE FUNCTION` time, so no migration window exists where the function is ever deployed unhardened.

**Search_path hardening — deciding reason.** The function is hardened with `SET search_path = pg_catalog, pg_temp` for **explicitness and version-independence**: name resolution inside the function is fixed at definition time regardless of whatever the calling session's `search_path` happens to be, rather than relying on Postgres's implicit-pg_catalog-first behavior continuing to hold under every possible caller configuration. This is *not* about avoiding a higher minimum-version requirement — inline `SET search_path` on `CREATE FUNCTION` is supported on every version this schema could plausibly target, so hardening vs. not hardening makes no difference to the version floor either way (see below). The attack this closes is not exotic: an operator with `ALTER ROLE ... SET search_path` or `ALTER DATABASE ... SET search_path` privilege — not an unusual grant for a migration or ops role — can reposition `pg_catalog` for any session using that role/database, at which point any *unhardened* function becomes hijackable by whatever a hostile or merely careless schema places earlier in that path. D-0005's rejected schema-per-tenant option is relevant here, not as a tie-in for its own sake: had it been chosen, routine per-tenant schema use would have made multi-schema `search_path` handling — and therefore this exact class of repositioning — a normal, frequent occurrence rather than an unusual administrative action. That is one more reason D-0005's row-level-tenancy choice was right, and one more reason this hardening is worth doing unconditionally regardless of which tenancy model had won.

**PostgreSQL version floor — pinned explicitly, not left implicit.** The floor is **PostgreSQL 12**, set by exactly one feature this schema depends on:

| Feature used in `04`'s DDL | Minimum PostgreSQL version | Binding? |
|---|---|---|
| `GENERATED ALWAYS AS (...) STORED` (generated columns) | 12 | **Yes — this is the actual floor.** |
| `EXCLUDE USING gist` + `btree_gist` on scalar equality types | 9.2 (btree_gist itself older still) | No — below the generated-column floor |
| Row-Level Security (`ENABLE`/`FORCE ROW LEVEL SECURITY`, policies) | 9.5 | No — below the generated-column floor |
| `tstzrange` / range types | 9.2 | No — below the generated-column floor |
| `gen_random_uuid()` (built-in since 13; available via `pgcrypto` on any earlier supported version) | n/a | No — `pgcrypto` covers every version back past 12 |
| Per-function `SET search_path` on `CREATE FUNCTION` | long-standing, predates every row above | No |

This belongs in `04` because below PostgreSQL 12, this schema **cannot be expressed at all** — `occupancy_range` (and `starts_at`/`ends_at`) have no fallback representation without generated columns. `08-deployment-and-operations.md`, once written, chooses the actual deployed version and hosting provider *above* this floor as an operational/cost/vendor-availability decision — a different question with a different owner than this one. **In practice this floor is non-binding:** PostgreSQL 12 reached end-of-life in November 2024, and every version any competent hosting provider offers today already clears it by multiple major versions. It is recorded anyway, honestly, because "what does this schema actually require" and "what will we actually run" are two different questions belonging to two different documents (`04` and `08` respectively) — conflating them would make a future version downgrade look safe by convention rather than because someone re-checked why 12 was the floor in the first place.

**Must not be silently reversed because:** reverting to the direct arithmetic `GENERATED ALWAYS AS` expression is not a stylistic rollback — it is unexecutable DDL (`42P17`) that fails at migration time outright, not a version that silently passes with different behavior. Removing the inline `SET search_path` (relying on a later `ALTER FUNCTION` step, or omitting it) reopens a real migration window in which the function is briefly deployed unhardened.

## D-0009 — RLS tenant-context lifecycle: transaction-scoped `SET LOCAL`, named roles, and impersonation-based admin access

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** D-0005 established *that* Postgres RLS backstops tenant isolation, keyed on a session GUC (`app.current_tenant_id`), but not *when or how* that GUC is set and cleared. `SET LOCAL`/`set_config(..., true)` only resets at `COMMIT`/`ROLLBACK` — on a pooled or long-lived connection (Horizon workers, PgBouncer), an ambient session-level `SET` would leak the previous request's or job's tenant to whoever reuses that connection next. This needed an explicit lifecycle, not an assumed one.
- **Decision:**
  - **Mechanism:** the tenant GUC is always set via a parameterized `set_config('app.current_tenant_id', ?, true)` call (never a string-interpolated `SET LOCAL ...`, which would be a SQL-injection vector), as the first statement inside an explicitly opened database transaction. This is the only mechanism used anywhere in the system — there is no session-level fallback.
  - **HTTP requests:** every tenant-scoped request (reads included) runs inside a transaction opened by a `SetTenantContext` middleware, which resolves `tenant_id`, sets the GUC, invokes the handler, and commits (or rolls back on exception). Wrapping reads in a transaction is the cost accepted for the GUC-reset guarantee to hold unconditionally, rather than depending on cleanup code running.
  - **Queue workers (Horizon):** the same shape via a `TenantScopedJob` base class — `tenant_id` is a required constructor argument (a job that doesn't extend this base, or omits it, cannot be dispatched), and a job-level queue middleware opens a transaction, sets the GUC from the job's own serialized `tenant_id`, runs `handle()`, and commits/rolls back. Because the GUC is transaction-scoped, a job can never inherit a prior job's tenant on a reused worker connection, *provided every tenant-touching job extends this base* — enforcing that is a test-suite concern (`07-testing-strategy.md`), not a runtime one.
    - **Retries** re-run `handle()` from the same serialized payload, so `tenant_id` is re-supplied automatically; a custom `__serialize`/`__wakeup` override that strips it is the one way this could silently break.
    - **Batches** may span tenants (e.g., a platform-wide rebooking-prompt sweep) as long as each job in the batch sets its own context independently — the batch mechanism itself carries no ambient tenant assumption.
    - **Tenant-less platform jobs** (e.g., purging `stripe_webhook_events`) use a separate `PlatformJob` base that sets no tenant GUC and is restricted to non-tenant-scoped tables.
  - **Connection pooling:** PgBouncer **transaction mode** is compatible and preferred, not merely tolerated — it reclaims a backend connection at the same `COMMIT`/`ROLLBACK` boundary where the GUC already resets, so the two lifecycles line up exactly. Session mode also works. What is incompatible: anything that promotes `SET LOCAL` to a session-level `SET`, or any connection-reuse behavior that spans a transaction boundary without a matching reset. Recorded as a forward constraint on `08-deployment-and-operations.md`'s eventual hosting/pooling choice, not resolved here.
  - **Roles:**
    - `bookslot_app` — the sole runtime role (HTTP and queue workers both authenticate as this). Ordinary role, fully subject to RLS, never granted `BYPASSRLS`.
    - `bookslot_migrator` — owns the tables and holds `BYPASSRLS`. Used only by migrations, seeders, backfills, and `pg_dump`/`pg_restore` — all offline, human- or CI-triggered, never reachable from a running request. Uses separate `.env`-configured credentials from `bookslot_app`, so the two can never be interchanged by configuration error.
    - **No separate live-request bypass role** — see the admin-path decision below.
  - **Platform-admin cross-tenant path:** the admin endpoint (`GET /api/admin/tenants/{id}/appointments`) authenticates as the ordinary `bookslot_app` role and sets `app.current_tenant_id` to the specific `{id}` being inspected — identical to any normal tenant-scoped request — gated by an app-layer check that the caller is `role = 'platform_admin'`. This **revises D-0005's "(e.g., `BYPASSRLS`)" parenthetical** for the live admin path specifically: `BYPASSRLS` remains reserved for `bookslot_migrator`'s offline use only. Rejected alternative: a literal `BYPASSRLS` role used live by the admin endpoint — simpler, but a bug in the admin authorization check combined with a live `BYPASSRLS` role reproduces the exact full-cross-tenant-leak failure mode RLS exists to prevent (worst case: every tenant's data, gated by one check). The impersonation approach keeps RLS fail-closed even for the admin path itself — worst case of an authz bug there is exposure of one wrong tenant, not all of them. There is now exactly one RLS-bypass surface in the whole system, and it is never reachable from a live request.
  - **Unauthenticated public booking path:** the client supplies only a `slug` (a deliberately public, shareable identifier — the booking-page URL itself). The server resolves `slug → tenant_id` via a parameterized lookup against `tenants` (outside the RLS boundary per `04`), and only that server-resolved value is passed to `set_config()` — never anything from the request body. A customer-supplied `tenant_id` anywhere in a public request payload is ignored server-side in favor of the slug-resolved value; this is not an injection vector because the client never controls the value that reaches the GUC. The manage-booking-link path (`GET /api/bookings/manage/{token}`) instead derives trust from the signed token itself.
- **Must not be silently reversed because:** switching any tenant-scoped code path to session-level `SET` instead of transaction-scoped `set_config(..., true)` reintroduces the cross-request/cross-job leak this decision exists to close; granting `BYPASSRLS` to a live-request role would collapse the admin-path guarantee back to the rejected alternative above.

## D-0010 — Off-session balance-charge mandate: full rendered-text evidence snapshot

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** D-0006's later balance charge (J5) is an off-session confirmation against the card saved at booking. Stripe's off-session model expects the cardholder to have agreed, at save time, to specific future-charge terms; without recorded evidence of that agreement, off-session SCA declines are more likely and a resulting dispute is hard to defend. This is distinct from no-show deposit forfeiture (J4), which retains an already-captured amount rather than initiating a new charge, and so needs disclosure rather than a charge mandate.
- **Options considered:**
  - **A dedicated `payment_mandates` table storing the full rendered mandate text as agreed to, not just a template identifier, plus timestamp/IP/user-agent/PaymentIntent ID/payment-method ID, linked to the appointment and into the existing audit trail — chosen.**
  - Store only a template-version identifier plus metadata, reconstructing text later from a versioned template — rejected: a dispute can arrive months after the charge, and this makes the evidence's integrity depend on old template files being preserved carefully forever rather than being self-contained in the record that must prove what a specific customer saw.
  - Rely on Stripe's own record of a completed Payment Element flow, with no additional structured storage — rejected: it demonstrates the customer paid a deposit, not that they specifically agreed to a *future, different-amount, later-triggered* off-session charge, which is exactly what an off-session dispute turns on.
- **Decision:** add `payment_mandates` (tenant-scoped) to `04-data-model.md`:

| Column | Type | Null | Notes |
|---|---|---|---|
| id | uuid | no | |
| tenant_id | uuid | no | |
| appointment_id | uuid | no | `REFERENCES appointments(id) ON DELETE RESTRICT`; one mandate per appointment, created at booking submission alongside the deposit PaymentIntent |
| mandate_text | text | no | The full rendered text the customer agreed to — a snapshot, not a template reference |
| mandate_template_version | text | no | Identifies which template produced `mandate_text`, for change-tracking; not relied on alone to reconstruct historical wording |
| balance_amount_disclosed | integer | no | Minor units; the exact remaining-balance figure disclosed at booking time |
| accepted_at | timestamptz | no | |
| accepted_ip | inet | no | |
| accepted_user_agent | text | yes | |
| stripe_payment_intent_id | text | no | The deposit PaymentIntent this mandate covers |
| stripe_payment_method_id | text | no | The saved payment method the future off-session charge will target |
| created_at | timestamptz | no | `now()` |

  A corresponding `booking_events` row (`event_type = 'mandate_accepted'`, `metadata` referencing the `payment_mandates.id`) links this into the audit trail already established for `stripe_webhook_events.payload` — so a dispute response can be assembled from `booking_events` + `payment_mandates` + `stripe_webhook_events` together, not three unrelated tables.
- **What the mandate discloses (content, not yet drafted as final copy):** studio name, service, deposit amount charged now, the exact remaining-balance amount that will be auto-charged to the same card once the studio marks the appointment `completed` (only applicable if the studio's balance policy is auto-charge), and a separate disclosure that a no-show forfeits the deposit per studio policy. Requires an explicit affirmative action (a checkbox) before the deposit PaymentIntent is created, implying a small future addition to the `POST /api/tenants/{slug}/bookings` request shape in `05-api-contracts.md` — not made this session, since `05` wasn't in this session's amendment scope; recorded as an open item below.
- **Reconciled with `06`'s disclosure-copy open question:** `06`'s flagged item (chargeback/dispute-liability disclosure) is owner-facing — a studio's own exposure as merchant of record. This mandate is customer-facing — the future charge itself. Related, both deferred to a copywriting/implementation session, but not the same document.
- **Explicitly not decided here:** the exact mandate wording, and whether it must satisfy a formal SCA mandate flow (vs. a strong disclosure) for a given card scheme/region — this depends on Stripe's specific rules and is real implementation-session research (possibly legal-adjacent), not invented in this session.
- **Must not be silently reversed because:** dropping to template-version-only storage would leave a future dispute unable to prove exactly what a specific customer agreed to if the template has since changed.

## D-0004 — No framework-allocation-ledger check for this repository

- **Date:** 2026-08-23 · **Status:** accepted (procedural, not technical)
- **Context:** the prompt that opened this repository's work explicitly
  states the public-track ledger rule doesn't apply to private
  repositories.
- **Decision:** this repository does not carry a `00a-ledger-confirmation.md`
  file, and no ledger-collision check gates its progress.
- **Why:** recorded here so a future session doesn't mistakenly reintroduce
  public-track ceremony that doesn't fit private commercial work.
