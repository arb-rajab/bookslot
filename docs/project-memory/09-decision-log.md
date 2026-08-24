# Decision Log
> Purpose: why things are the way they are, so decisions are not silently undone.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-24 (Session 7 — confirm-payment tenant-context fix and three further rulings, D-0021 through D-0024)

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
- **Resolved by D-0024 (Session 7):** both before and after are configurable at MVP. See D-0024 for the ruling and why this required no schema change.
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

**Extended by D-0021 (Session 7):** this decision's public-path tenant-resolution list was originally two concrete instances (slug-based; the one manage-booking token). D-0021 found a third public endpoint (`POST /api/bookings/{id}/confirm-payment`) that fit neither, and closed the gap by generalizing the *token* half of this decision into a reusable mechanism class — a signed, purpose-scoped, tenant-carrying token — rather than inventing a third, unrelated mechanism. This decision's own text is otherwise unchanged; see D-0021 for the generalization and its reasoning.

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

## D-0011 — `pending_payment` hold window: 15 minutes, as a configuration value

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `04-data-model.md` and `12-session-handoff.md` have carried "the exact hold-window duration for `pending_payment → cancelled` (J2)" as an open ruling since Session 2 — the mechanism (a scheduled job cancels an unpaid hold) was decided, the number was not.
- **Options considered:**
  - Hard-code a literal minutes value in the scheduled-job class — rejected: makes the number a code change (and a deploy) to revise, when this is explicitly a guess pending real pilot data, not a stable constant.
  - **A configuration value (e.g. Laravel `config('booking.hold_window_minutes')`, sourced from an environment variable, defaulting to 15) — chosen.**
  - A per-tenant, owner-configurable setting — rejected *for now*, not because it's a bad idea, but because nothing in this ruling asked for per-studio customization, and adding a schema column and owner-facing setting for it would be widening this session's scope without asking first. If a future session decides studios should tune their own hold window, that is a deliberate, separate scope decision, not something to infer from "make it configurable."
- **Decision:** the hold window is **15 minutes**, expressed as a single platform-wide configuration value, not a literal embedded in application logic. It is read by both the booking-creation path (to compute when the hold expires) and the scheduled job that enforces expiry, from the same configuration source, so the two can never drift apart.
- **Why 15, and why this is not pretending to be validated:** 15 minutes is a defensible guess — long enough for a customer to complete a Payment Element flow including a 3DS challenge, short enough that a slot isn't tied up indefinitely by an abandoned checkout — but it is a guess. No real booking-funnel data exists yet to say how long real customers actually take. This is explicitly **not** the same class of open item as "needs your ruling" — the number is chosen and is not blocked on anything — but the *correctness of the number* is pilot-dependent, so the open item in `12-session-handoff.md` tracking "is 15 minutes right" stays open, re-scoped from "no value chosen" to "value chosen, provisional pending real usage data."
- **Must not be silently reversed because:** changing this to a hard-coded constant would remove the ability to retune it from real pilot data without a deploy — precisely the case this decision anticipates.

## D-0012 — Buffer has no default: required, explicit input at service creation (amends D-0008)

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** D-0008's DDL (as corrected in Session 4) declares `buffer_before_minutes integer NOT NULL DEFAULT 0` and `buffer_after_minutes integer NOT NULL DEFAULT 0` on `appointments`, snapshotted from "the service/studio's buffer configuration" — but no such configuration column was ever actually added anywhere in `04-data-model.md`; the snapshot source didn't exist. This ruling closes both gaps at once: it decides the default-value question D-0008 explicitly left open, and it supplies the missing configuration field the snapshot mechanism was already assuming.
- **Options considered:**
  - Default to zero — rejected. A studio owner who doesn't think about buffer at service-creation time silently gets back-to-back appointments with zero turnaround for needle disposal, station breakdown, or sterilization. That is a real operational hazard for this vertical specifically (00's tattoo-studio framing), not a hypothetical edge case, and a silent zero-by-inattention default is the worst way to arrive at it.
  - A provisional non-zero default (e.g. 15 minutes) — rejected. Any non-zero number invented here would be exactly the kind of business-policy guess `00`'s business assumptions section is careful not to fabricate — turnaround time genuinely varies by service (a small flash tattoo vs. a multi-hour custom piece) and by studio, and a platform-wide guessed default would be wrong for most services most of the time in a way that's worse than forcing the choice.
  - **No default — buffer is a required field the owner must set explicitly when creating a service, for both `buffer_before_minutes` and `buffer_after_minutes` — chosen.**
- **Decision:**
  - `services` gains `buffer_before_minutes integer NOT NULL` and `buffer_after_minutes integer NOT NULL` (no `DEFAULT` clause — an `INSERT` that omits either column fails, rather than silently getting zero), with the same `CHECK (... >= 0 AND ... <= 1440)` bounds already established for `appointments`' snapshot columns in D-0008.
  - `appointments.buffer_before_minutes` / `buffer_after_minutes` remain `NOT NULL` but lose the `DEFAULT 0` clause — they are always populated explicitly by application code from the owning service's (now-required) buffer configuration at booking-creation time, per D-0008's snapshot rule; removing the column default is a statement of intent (this value is never meant to arrive by omission) even though the application, not a bare `INSERT`, is the only real write path.
  - This does not decide D-0008's separate, still-open question of whether MVP restricts buffer to "after only" or allows both before and after per service — both columns exist either way; a studio is free to set either to `0` explicitly (an informed zero, not an inattentive one) if it wants back-to-back scheduling for a given service.
- **Why this is authorization/onboarding-flow reasoning, not just a schema tweak:** per `01`'s non-paternalism framing (the product doesn't silently decide business policy on a studio's behalf — see the parallel reasoning for no-show disposition, FR-08), the right response to "we don't know the correct number" is to make the owner choose deliberately, not to have the platform guess quietly on their behalf. Forcing an explicit choice at the one moment (service creation) the owner is already thinking about that service's specifics is the cheapest point to ask.
- **Not made this session:** the actual UI/copy prompting the owner for this value, and `05-api-contracts.md`'s `POST/PATCH /api/owner/services` request/response shapes reflecting the new required fields — `05` was not in this session's authorized scope for this ruling (only D-0008 and `04` were named); adding the fields to `05` is a small, low-risk follow-up left as an open item rather than done here without being asked.
- **Must not be silently reversed because:** reintroducing a numeric `DEFAULT` on either `services` column would silently reopen the zero-by-inattention hazard this ruling exists to close.

### Postscript (Session 6, 2026-08-24) — what this gap says about the verification, not just the fix

D-0012 fixed a real bug: D-0008's "snapshotted from the service/studio's
buffer configuration" language assumed a source column that was never
actually added anywhere in `04-data-model.md` until this ruling supplied
it. That means D-0008's snapshot-at-booking semantics — the actual property
D-0008 exists to provide, that a studio changing its buffer later never
retroactively alters an already-created booking's occupancy window — were
unimplementable as written for four sessions (Sessions 3 through this
gap's discovery). During that entire span, the DDL executed without error,
the exclusion constraint behaved correctly against every case tested, and
nothing in the process caught it.

**Why not:** every test and every execution check across those sessions
asked a version of "is the schema internally consistent" — does the DDL run,
does the constraint reject an overlapping insert, does the containment CHECK
hold, does `occupancy_window()` resist a hostile `search_path`. None of them
asked "does changing a studio's buffer configuration actually leave existing
bookings' `occupancy_range` values unchanged and give new bookings the new
value" — the literal guarantee D-0008's own text describes as its purpose.
A schema can be perfectly self-consistent while the specific property it was
introduced to deliver was never exercised at all, because self-consistency
and delivering-the-stated-property are different claims that happen to look
identical until the second one is actually tested. See `07-testing-strategy.md`'s
new buffer-config-change case (added Session 6) — the direct fix for this
class of gap, not just for this one instance of it.

## D-0013 — FR-16 staff cross-visibility: own bookings only at MVP; a studio-level toggle is a Paid-tier feature

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `02-requirements.md` FR-16 has carried "whether staff can see other staff's bookings within the same studio is explicitly undecided" as an open ruling since Session 2.
- **Options considered:**
  - Staff can see all of a studio's bookings by default — rejected: this is an authorization default a studio owner should opt into, not a starting assumption about what an owner is comfortable exposing to every artist on the roster.
  - **Staff see only their own bookings at MVP; an owner-configurable toggle to widen this to all-staff-visible exists as a real, named capability but is tier-gated to Paid — chosen.**
- **Decision:** FR-16 is settled: staff-facing endpoints return only the authenticated staff member's own bookings at MVP, with no owner-facing setting to change that yet. The wider-visibility toggle is recorded as a real, specific Paid-tier feature (not a vague "maybe later"), because the correct narrow default is now fixed either way.
- **Why the narrow default is the safe direction to be wrong in:** this is an authorization boundary, not a schema decision — loosening it later (shipping the toggle) is a cheap, additive change with no migration; the reverse (having shipped all-staff-visible by default and later needing to claw that visibility back) would mean walking back something staff had already gotten used to seeing, a materially worse position to be in. When a default's cost of being wrong is asymmetric like this, the narrow side is the one to start on.
- **Must not be silently reversed because:** shipping all-staff-visibility as the new MVP default without adding the toggle first would remove the one thing (owner opt-in) this decision is actually about.

## D-0014 — No-show rebooking: no automatic prompt; manual re-invite remains available as an explicit owner action

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `02-requirements.md` J10 already defaulted to "no automatic rebooking prompt for a no-show" but flagged it `[UNVAL]` — a default chosen for lack of a better answer, not a confirmed ruling.
- **Decision:** confirmed as the real ruling, not just a placeholder default: a `no_show`-terminated appointment does **not** trigger the automated, fire-once rebooking prompt (`FR-14`/J10) that a `completed` appointment gets. Separately, an owner retains the ability to manually re-invite a specific customer — a deliberate, one-off action sending that customer a link back to the public booking page — so the *capability* to re-engage a no-show customer exists without an automatic system nudging every no-show customer to rebook regardless of the studio's own judgment about that specific client.
- **Why manual re-invite, not silence:** a studio may reasonably want to give a specific no-show customer another chance (a one-off miscommunication, a genuine emergency) without the product automatically extending that same courtesy to every no-show indiscriminately — the owner's judgment about a specific customer relationship is exactly the kind of call `01`'s non-paternalism framing says the product shouldn't make on the studio's behalf, in either direction (neither "always re-invite" nor "never allow re-inviting").
- **Not made this session:** the manual re-invite action's own endpoint shape belongs to whichever session next has `05-api-contracts.md`'s owner-endpoint surface in scope — recorded here as a decided capability, not a spec.
- **Must not be silently reversed because:** removing the manual re-invite capability entirely would leave a studio with no way to recover a no-show relationship it judges worth recovering, which is a real regression from what this ruling grants.

## D-0015 — Off-session mandate: `mandate_accepted` added to the booking-creation contract now; consent copy and SCA research stay deferred

- **Date:** 2026-08-24 · **Status:** accepted (partial — see split below)
- **Context:** D-0010 (Session 3) established the `payment_mandates` evidence table and flagged two things as not decided: the exact mandate wording/copy (and whether a formal Stripe SCA mandate flow is required for a given card scheme/region), and a small addition to `05-api-contracts.md`'s booking-creation request to carry the customer's acceptance. Both have been carried as one bundled open item since Session 3, which is exactly why neither has moved — they are different kinds of work with different blockers, and bundling them let the schema-shaped half wait on the legal/copywriting half.
- **Decision — split explicitly into two:**
  - **(a) Consent copy and formal-SCA-mandate research: DEFERRED**, unchanged in substance from D-0010's original framing. This is legal-adjacent work (what the mandate actually says, and whether Stripe/card-network rules require more than a strong disclosure for a given scheme/region) that has no schema cost to waiting on — nothing about the data model or contract shape depends on knowing the final wording. Stays an open item in `12-session-handoff.md`, explicitly marked deferred, with the reason above so a future session doesn't re-bundle it with (b) again.
  - **(b) `mandate_accepted` on `05`'s booking-creation request: DECIDED AND ADDED NOW.** This is contract shape, not copy — it doesn't need the final wording to exist, only a place to record that *some* version of it was shown and agreed to. `POST /api/tenants/{slug}/bookings`'s request body gains two fields: a boolean `mandate_accepted` (must be `true` for the request to succeed — `422 VALIDATION_FAILED` otherwise) and a string `mandate_template_version` (echoing back which version of the mandate text the customer saw, populated into `payment_mandates.mandate_template_version` verbatim). How the client obtains the text/version to display before submitting is **not** decided here — that's part of the still-deferred copywriting/implementation work in (a); this field only guarantees the contract has somewhere to put the answer once that work lands, closing the specific gap `04-data-model.md`'s `payment_mandates` table notes and `12`'s handoff have both named since Session 3.
- **Why splitting was the right call, not scope creep:** the two halves kept getting re-bundled specifically because touching one meant touching "the mandate item" as a whole, which pulled in the deferred half every time. Treating them as one item is what caused the drift; treating them as two lets (b) close now without waiting on (a), and keeps (a) honestly deferred instead of technically-blocking-but-not-actually-worked-on.
- **Must not be silently reversed because:** merging these back into one item would reproduce the exact drift this decision exists to stop.

## D-0016 — Test framework: Pest

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `07-testing-strategy.md` was deliberately written framework-agnostic (layers and cases, not syntax), with the PHPUnit-vs-Pest choice left open as a Session 4 open question, "needs a decision in the first implementation session, not urgently before then."
- **Decision:** Pest.
- **Why now, ahead of the first implementation session:** naming the framework doesn't change any test case or layer already documented in `07` — it costs nothing to decide early, and having it settled means the first implementation session can start writing tests immediately instead of re-opening a framework debate that has no bearing on this project's actual test design.
- **Must not be silently reversed because:** switching frameworks after real test files exist is a real migration cost (syntax, plugin ecosystem, CI config), not a documentation change — this is worth deciding once.

## D-0017 — Tenant-isolation suite: confirmed as a blocking fast-gate check, with a stated runtime budget

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `07-testing-strategy.md` already recommended the tenant-isolation suite as "non-negotiable" inside the fast `composer ci:check` gate, but its own Open Questions section still framed this as needing "an explicit yes" rather than being settled — and named no runtime budget for the suite itself, only for the fast gate as a whole (~5 minutes).
- **Decision:** the tenant-isolation suite blocking the fast gate is settled, not a recommendation awaiting confirmation. It carries its own runtime budget, inside the fast gate's overall ~5-minute target: **under 60 seconds.** This is achievable because the suite's case count is manifest-driven and bounded (it scales with the number of tenant-scoped tables and `05`-derived endpoints, not with an open-ended amount of business logic), and it already shares the same containerized Postgres instance the rest of the fast gate pays for.
- **Why a stated budget, not just "keep it fast" as an aspiration:** a blocking suite that grows past its budget doesn't fail safely — it gets bypassed by whoever is trying to ship under time pressure (a `--skip-tests` flag reached for "just this once," a habit of ignoring a slow CI stage). That failure mode is social, not technical, and a stated numeric budget is what makes "this suite got too slow" a visible, actionable signal instead of a slow, silent erosion of what "blocking" actually means in practice.
- **What happens when the budget is exceeded:** in order of preference — (1) **optimize** first (e.g., reuse a single templated test database/schema across the suite's cases instead of re-provisioning per case, parallelize the manifest-completeness and RLS-enforcement catalog checks, which are independent per table); (2) **split/shard** the suite to run its cases concurrently within the fast gate if optimization alone isn't enough; (3) only as a last resort, **move specific slow cases out** to the nightly tier — but only cases that aren't themselves proving the core fail-closed guarantee (e.g., a newly added, unusually expensive case for a rare relationship-traversal pattern), never the manifest-completeness or RLS-enforcement catalog checks themselves, which are what makes the suite trustworthy against a new unprotected table. Silently letting the budget slip without doing one of these is not an option this decision leaves open.
- **Must not be silently reversed because:** moving this suite to nightly-only, or dropping the runtime budget without one of the three responses above, reopens exactly the risk `01`'s Definition of MVP-complete calls tenant isolation "the one correctness property this product cannot ship without."

## D-0018 — E2E scope: the single J1 smoke test is settled, not a placeholder recommendation

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `07-testing-strategy.md`'s Open Questions section framed the one J1 (book + pay a deposit) browser smoke test as "a recommendation, not a settled decision," with full E2E-vs-feature-layer-only left open.
- **Decision:** settled — the single J1 smoke test through the real Nuxt frontend against the real API is the entire E2E layer at MVP. No broader E2E coverage is planned; failure paths, edge cases, and admin/staff flows stay at the feature layer, per `07`'s existing reasoning (cheaper, more reliable, and E2E's per-test cost is real for a solo-track suite).
- **Must not be silently reversed because:** expanding E2E scope "just a little" without a fresh reason reintroduces exactly the maintenance cost `07` already reasoned this layer should stay deliberately tiny to avoid.

## D-0019 — Statement descriptor and dispute-disclosure copy: deferred, with a technical note for whoever picks it up

- **Date:** 2026-08-24 · **Status:** deferred
- **Context:** D-0006 already flagged the statement descriptor as "an open product question, not a schema blocker," and `06-security-threat-model.md`'s Session 2 amendment flagged dispute-liability disclosure copy as needing to reach studio-onboarding copy in a future session. Both are writing/product-copy tasks, not schema or contract work.
- **Decision:** both stay deferred — this is copywriting/product work with no schema dependency, not something this session should invent placeholder text for. What this decision adds is a technical note so whoever picks this up next doesn't treat it as pure writing:
  - **Statement descriptors are constrained, not freeform.** Stripe requires 5–22 characters, Latin characters only, at least one letter (not digits-only), and rejects the characters `<`, `>`, `'`, `"`, and `*`. A studio's display name (`tenants.name`) will not always fit this — punctuation, non-Latin characters, or length alone can violate it — so the onboarding flow needs a distinct, validated "statement descriptor" input, not a naive reuse of the studio's display name.
  - **Connect charge type changes whose descriptor the customer sees.** Under Connect, a charge created with `on_behalf_of` (alongside a destination charge, D-0006's model) shows the *connected account's* (the studio's) statement descriptor rather than the platform's by default; the exact behavior depends on which of `on_behalf_of`/`statement_descriptor`/`statement_descriptor_prefix` are set, and Stripe's own Connect statement-descriptor documentation is the authoritative source for the current combination rules, not this note. The practical implication: deciding "what does the customer's card statement say" is not purely a copywriting question — it has a real integration-parameter dependency that the eventual implementation session needs to resolve against Stripe's current docs, not assume from this note alone.
- **Must not be silently reversed because:** nothing to reverse — this is a deferral, recorded so it isn't silently forgotten and so the next session doesn't treat it as copy-only work when it isn't.

## D-0020 — `bookslot_migrator` credential stays out of the automated deploy pipeline (refines D-0009)

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** D-0009 named `bookslot_migrator` (holds `BYPASSRLS`) as used only "offline, human- or CI-triggered" — treating those two as roughly equivalent, since both are "never reachable from a running request." Writing `08-deployment-and-operations.md` forced a more honest look at that equivalence: if migrations run automatically as part of an automated CI/CD deploy pipeline, the migrator's `BYPASSRLS`-capable credential must live as a CI secret, reachable by every pipeline run — a materially larger and more automatable attack surface (a compromised pipeline config, a malicious PR touching CI YAML, a compromised third-party Action) than a credential a human deliberately fetches for one manual invocation. D-0009's "human- or CI-triggered" phrasing didn't distinguish these, and this decision does.
- **Options considered:**
  - Migrator credential lives in the CI/CD pipeline's secret store, migrations run automatically on every deploy — rejected as the default: this is the single `BYPASSRLS`-capable credential in the entire system (D-0009), and putting it inside the same automated system that runs on every merge (with its larger, more automatable, and more supply-chain-exposed surface) trades away most of the "never reachable from a live/automated path" property D-0009 was designed around, for the convenience of not having a manual release step.
  - **Migrations run as a separate, manually-triggered step outside the automatic deploy path — chosen.** A human operator runs the migration explicitly (e.g., from a secured bastion/operator session, using a migrator credential fetched just-in-time from a secrets manager for that one invocation, or via a CI job gated behind a required manual approval that only that gated job — never the general build — can read the credential for), before the corresponding application version is promoted.
- **Decision:** `bookslot_migrator`'s credential is never stored as a general CI/CD secret reachable by ordinary pipeline runs. Migrations are a deliberate, human-gated step in the release process, not an automatic consequence of merging to `main`.
- **Cost, stated plainly, not papered over:** this gives up a fully automated merge-to-production pipeline. Every release that includes a migration requires a human to explicitly run it, in the right order relative to the application deploy (see `08-deployment-and-operations.md`'s migration-safety section) — an extra manual step that must be remembered and done correctly under whatever time pressure a release carries, which is itself a real (if smaller and more contained) risk this decision accepts in exchange for keeping the `BYPASSRLS` surface out of the larger automated system.
- **Must not be silently reversed because:** adding `bookslot_migrator` to general CI secrets "just for convenience" on some future deploy-automation push would quietly undo the entire point of this decision — the blast-radius argument above would need to be re-examined and explicitly overridden, not bypassed by a config change nobody thought was a security decision.

## D-0021 — Confirm-payment tenant context: a booking-scoped signed token replaces the raw appointment ID (amends D-0009)

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** Session 6's contradiction audit found that `POST /api/bookings/{id}/confirm-payment` is public, unauthenticated, and keyed only by a bare, unscoped `appointment_id` — matching neither of D-0009's two named public-path tenant-resolution mechanisms (slug-based; the manage-booking signed token). This is not a documentation gap: an honest implementation that never sets `app.current_tenant_id` from unauthenticated input has no way to resolve tenant context from a bare ID at all, since the row that would tell it the tenant is itself RLS-protected — a chicken-and-egg deadlock, not a bug fixable by being careful. The realistic way an implementer "fixes" that deadlock is to resolve `tenant_id` from `appointment_id` via an unscoped lookup (a raw query, or reaching for the `bookslot_migrator`/`BYPASSRLS` role for "just this one lookup") — which reopens exactly the live, unauthenticated-request `BYPASSRLS` exposure D-0020 was written to prevent, and, short of that, lets anyone holding a real `appointment_id` (obtained by disclosure — a forwarded email, shared-device browser history, a URL-logging pipeline — not brute force, since D-0007/`04` already uses `uuid`, not sequential integers) query and interact with another tenant's booking/payment state. UUID entropy raises the bar from trivial to "requires disclosure of one real ID," but does not close the hole, because the ID was never meant to function as a credential in the first place.
- **Options considered:**
  - **A booking-scoped, purpose-scoped signed token, generalizing D-0009's existing manage-booking token mechanism rather than inventing a third one — chosen.**
  - Derive tenant context from the Stripe PaymentIntent (retrieve the PI server-side, read tenant_id from its metadata) — rejected as the default: adds a synchronous external Stripe call, and a new failure mode (Stripe unreachable), to the customer-facing deposit-confirmation hot path, and creates a new invariant to maintain forever (PI metadata must always carry a correct `tenant_id` and never be stripped by any code path that touches the PaymentIntent). Would win if a future session finds Stripe-metadata-as-source-of-truth is needed for another reason — not needed to close this specific gap.
  - Make the endpoint authenticated — rejected: contradicts `02-requirements.md`'s actor model (customers have no account relationship with bookslot at all). A session-cookie variant is a bearer credential by another name and, unlike a token, does not survive a customer switching devices mid-checkout (a real J1/J2 scenario).
  - Remove the endpoint; rely on Stripe webhooks alone — rejected: contradicts `04-data-model.md`'s own booking-state-machine narrative (confirmation is synchronous on the no-3DS path, per J1 step 5) and the precedent `05-api-contracts.md` endpoint 6 (`balance/charge`) already set — a synchronous, actionable `200` response for a payment outcome the client needs to render UI around, not a webhook-only side channel the client has no way to observe directly. Revisiting that UX pattern is a real redesign, out of this session's authorized scope.
- **Decision:**
  - `POST /api/tenants/{slug}/bookings`'s `201` response gains a new field, `payment_confirmation_token` — **deliberately distinct from `manage_token`**, not a reuse of it. Least-privilege reasoning: `manage_token` is scoped to viewing/cancelling a booking; a leaked `manage_token` must not also grant the ability to drive payment confirmation/retry, which is a materially more sensitive action on a money-carrying path.
  - The endpoint's route changes from `POST /api/bookings/{id}/confirm-payment` to **`POST /api/bookings/{token}/confirm-payment`** — the token is the sole path identifier; a raw, client-supplied `appointment_id` no longer appears anywhere in this endpoint's contract.
  - The token is a signed (same signing infrastructure as `manage_token` — no new signing scheme introduced), stateless capability carrying `tenant_id`, `appointment_id`, `purpose = "confirm_payment"`, `issued_at`, and an `expires_at` claim set to booking-creation time plus the hold-window configuration value (D-0011) plus a small fixed grace period — an independent, defense-in-depth expiry that holds even if the hold-window-enforcement job itself fails to run.
  - **Verification order, strictly:** (1) verify the token's signature, `purpose`, and `expires_at` before anything else — a bad signature, wrong purpose (e.g., a `manage_token` presented here), or expired token is rejected with a generic `404 { "error": "INVALID_OR_EXPIRED_TOKEN" }` that reveals nothing about whether any appointment exists (there is no separate raw ID in the request to leak against); (2) only once verified, set `app.current_tenant_id` from the token's own signed (trusted) `tenant_id` — never from any other input; (3) perform the RLS-protected read/write against the token's `appointment_id`.
  - **Not single-use.** Valid for repeated calls while the appointment's `status = pending_payment`, supporting J2's retry-with-a-different-card flow. Once the appointment leaves `pending_payment`, further calls are idempotent status echoes (`{"status":"confirmed"}`) or the existing `409 BOOKING_EXPIRED`, never a fresh mutation — the same idempotency discipline J9 already established for webhook delivery, now also gated on token validity.
  - **No new storage.** Like `manage_token`, this is a stateless signed capability verified by signature alone, not a stored, revocable row — `04-data-model.md` needs no schema change for this decision. (Recorded explicitly since this session was instructed to touch `04` only if storage were required.)
  - **This generalizes, rather than replaces, D-0009's token mechanism:** D-0009's framing of "exactly two public-path mechanisms" (slug; one specific token) is revised to "slug-based, and a signed-token capability class," of which the manage-booking token and this new payment-confirmation token are two distinct, purpose-scoped instances. A future public endpoint needing tenant context should default to a third instance of this same class rather than reopening this question. See the pointer added to D-0009 above.
- **Consequences for other endpoints, raised rather than acted on:** none of `05`'s other public endpoints need a change — the manage-booking-link endpoints already fit the (now-generalized) mechanism as-is. No owner/staff/admin endpoint is affected; this decision is confined to one public, unauthenticated endpoint.
- **Must not be silently reversed because:** reverting the route to accept a raw `appointment_id` again reopens the exact hole this decision closes; reusing `manage_token` for this purpose instead of a distinct token would silently widen what a leaked manage-booking link can do.

## D-0022 — Erasure carve-out for `payment_mandates`: retained in full, including `accepted_ip`/`accepted_user_agent`, on a legal-claims basis

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** `07-testing-strategy.md`'s Session 6 amendment flagged a real, unresolved gap: whether/how a customer erasure (FR-18) interacts with that customer's `payment_mandates` rows — which carry `accepted_ip` (arguably personal data) — was unspecified anywhere in `04-data-model.md`. `payment_mandates` has no direct customer-identifying column (no `customer_id`, no name/email/phone) — it links only via `appointment_id`; the actual identifying fields (`customers.name`/`email`/`phone`) are already nulled in place by `04`'s existing erasure handling, unchanged by this decision. What was actually undecided is narrower and specific: does erasure touch `payment_mandates` at all, and specifically its `accepted_ip`/`accepted_user_agent` columns.
- **Ruling (given):** mandate evidence is retained after customer erasure — this is a deliberate carve-out from `06`'s general erasure handling, not a conflict with it, because mandates are dispute evidence: erasing them would destroy the ability to defend a chargeback for a transaction that legitimately occurred, and privacy regimes generally permit retention on a legal-claims basis (an explicit statutory exception to erasure in comparable regimes — e.g. GDPR Art. 17(3)(e)-equivalent "for the establishment, exercise or defence of legal claims" — not an invented workaround).
- **Decision, precise per-column:**
  - `payment_mandates` is **never modified or deleted** by a customer erasure, in full — every column survives exactly as recorded at accept-time: `mandate_text`, `mandate_template_version`, `balance_amount_disclosed`, `accepted_at`, `stripe_payment_intent_id`, `stripe_payment_method_id`, and (see below) `accepted_ip`/`accepted_user_agent`.
  - **`accepted_ip` and `accepted_user_agent` are classified as evidentiary, not identifying, and are retained.** Their retained value is narrow and specific: corroborating, at a future dispute, that the accept-time request came from a plausible, consistent session/device/geography — exactly the forensic purpose Stripe-dispute defense turns on. This is a different purpose from `customers.name`/`email`/`phone`, whose only function is identifying/contacting the person; nulling IP/user-agent would gut the one purpose this table exists for (dispute defense) while doing nothing `customers`' own anonymization doesn't already accomplish for the "stop identifying this person" purpose. General data-protection classification of IP as personal data does not change this: retention here is justified per-purpose (legal-claims/dispute-evidence), not by reclassifying IP as non-personal.
  - `customers.name`/`email`/`phone` continue to be nulled in place exactly as `04`'s existing Soft delete matrix already specifies — this decision does not change that.
  - `appointments` and `booking_events` are unaffected (already true per `04`'s existing matrix) — both remain linked via `appointment_id`, so a mandate stays reconstructable alongside its booking's audit trail after the linked customer's erasure.
- **Why this is a carve-out, not a conflict, stated for `06`:** `06`'s GDPR-erasure-equivalent control is about a studio's own end customer's identifying data — it was never meant to reach evidence of an already-completed, legitimate transaction that the business (and bookslot, as processor) has an independent, recognized legal basis to retain. Recording the carve-out explicitly in `06` prevents a future session from reading `06`'s erasure language as requiring `payment_mandates` to be touched.
- **Must not be silently reversed because:** nulling `accepted_ip`/`accepted_user_agent` (or deleting `payment_mandates` rows) on a future "more privacy-conservative" pass would silently destroy the specific dispute-defense value D-0010 built this table for, without being asked to trade that value away.

## D-0023 — `notification_deliveries.purpose` gains `rebooking_invite`, distinct from `rebooking_prompt`

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** D-0014/FR-23 added a manual, owner-triggered re-invite action, and `05-api-contracts.md`'s Session 6 definition of `POST /api/owner/customers/{id}/re-invite` flagged that `04-data-model.md`'s `notification_deliveries.purpose` CHECK list (`reminder_7d, reminder_24h, reminder_2h, rebooking_prompt`) has no value to record this send against — recorded as an open item, not invented at the time since `04` wasn't in that session's scope.
- **Ruling (given):** add `rebooking_invite` as its own distinct value, separate from the automatic `rebooking_prompt` (J10/FR-14).
- **Decision:** `notification_deliveries.purpose`'s CHECK list becomes `reminder_7d, reminder_24h, reminder_2h, rebooking_prompt, rebooking_invite`. `rebooking_invite` records exactly one thing: a manual, owner-initiated re-invite (FR-23), sent immediately rather than on a schedule.
- **Why a distinct value, not a shared one, is the actual point:** D-0014 made re-invites manual-only specifically so an owner's judgment about a specific customer relationship — not an automatic system nudge — decides whether a no-show customer is re-invited. A shared `rebooking_prompt` value would make the two indistinguishable in the audit trail, silently erasing the one property (manual vs. automatic) D-0014 exists to keep visible. The distinct value is what makes that boundary auditable, not merely policy.
- **Must not be silently reversed because:** collapsing this back into `rebooking_prompt` would remove the ability to tell, from the data alone, whether a given re-engagement message was the automatic fire-once prompt or a deliberate owner action — the exact distinction D-0014 was decided to preserve.

## D-0024 — Buffer scope: both before and after remain configurable at MVP (amends D-0008)

- **Date:** 2026-08-24 · **Status:** accepted
- **Context:** D-0008 left open whether MVP restricts buffer to "after only" (cleanup time following a service) or allows both before and after per service. D-0012 (Session 5) resolved that a value must be chosen explicitly (no default), without resolving *which* scope. `04-data-model.md` and `05-api-contracts.md` have both been written against both-configurable as the non-presupposing default in the meantime, and `12-session-handoff.md` has carried the before/after question open across three sessions.
- **Ruling (given):** both configurable, keeping what `04`/`05` already document — closing the open item, not changing the schema.
- **Decision:** MVP supports both `buffer_before_minutes` and `buffer_after_minutes` as independent, owner-set values per service, exactly as already implemented in `04`'s `services`/`appointments` columns and `05`'s service-management endpoint. No schema or contract change follows from this ruling — it is a formal closure of an already-built-both-ways design, not a new build.
- **Why this was deferred across sessions for no real reason, stated plainly:** the open item was implicitly waiting for a "resolving input" — real pilot data on typical studio turnaround patterns — that was never going to arrive from design work alone, because this was never actually a question that needed validated data: it's a configuration knob, not a policy commitment. A studio that wants zero pre-appointment buffer simply sets `buffer_before_minutes = 0` (an informed zero, per D-0012) — there was never a real product cost to supporting the more general case, only the cost of leaving a decided-in-practice question formally open. The cost of supporting both is one column that already exists (`buffer_before_minutes`); restricting to after-only would have been the actual schema change, and no session across four passes at this schema found a reason to make it.
- **Must not be silently reversed because:** restricting to after-only in a future session without a real, new product reason would remove configurability studios may already be relying on, for no benefit this ruling's reasoning identifies.
