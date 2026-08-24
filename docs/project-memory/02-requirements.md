# Requirements
> Purpose: testable statements of what the system must do and how well.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-24 (Session 2 — Requirements and Data Model; amended Session 5 — rulings recorded)

## Amendment (Session 5, 2026-08-24) — FR-16 and J10 resolved by ruling

Two items this file carried as open since Session 2 were resolved by explicit
ruling this session (`09-decision-log.md` D-0013, D-0014) — not fixed
silently, recorded here per this pack's amendment convention:

- **FR-16 (staff cross-visibility)** is no longer "explicitly undecided" —
  see the updated FR-16 row and the removed "Open question needing a ruling"
  section below. D-0013 has the full reasoning.
- **J10 (no-show rebooking prompt)** is no longer `[UNVAL]` defaulted to "no"
  — it's a confirmed ruling, and a new **FR-23** records the separate manual
  re-invite capability D-0014 adds alongside it.

This supersedes Session 0/1's light sketch below with the full pass that
session deferred. It is written after — and traceable to — the three
architectural decisions resolved this session (`09-decision-log.md`
D-0005/D-0006/D-0007) and drives `04-data-model.md` and
`05-api-contracts.md`. Requirements that depend on a business assumption
`00-project-brief.md` explicitly marks as unvalidated are flagged **[UNVAL]**
rather than stated as settled fact.

## Actors

| Actor | Authenticated? | Notes |
|---|---|---|
| Studio owner | Yes (Laravel session/API token) | One per tenant at minimum; manages services, staff, deposit rules; sees all bookings/payments for their own tenant only |
| Artist / staff | Yes | Belongs to exactly one tenant; may or may not have a login yet (a staff/resource profile can exist and be bookable before the person has set up dashboard access — see `04-data-model.md`'s `staff` vs `users` split) |
| Client / customer | **No** | Books via the tenant's public page with no account relationship to bookslot itself (00's framing: "their relationship is with the studio"). This has real schema consequences — see below |
| Platform admin | Yes (separate, internal-only role, not tenant-scoped) | Cross-tenant support/ops access; not part of any studio's `tenant_id` |

**Schema consequence of unauthenticated customers:** a booking cannot be
authorization-gated the way an owner/staff action can. The public booking
endpoints must be reachable with no session, rate-limited and validated
defensively (see NFR below), and a `customer` record is created or matched
*during* the booking flow itself (by tenant-scoped email match), not
pre-provisioned. A customer has no password and no way to log in at MVP —
"my booking" lookups (e.g. a confirmation/manage-booking link) must work via
a signed, single-purpose URL token, not session auth.

## User journeys (MVP scope, end to end, including failure paths)

Numbered for traceability from the FR table below. Each references the
booking/payment states defined in `04-data-model.md`.

### J1 — Happy path: booking + deposit
1. Customer opens the tenant's public booking page (SSR — see NFR).
2. Customer picks a service and an available slot for a staff member.
3. Client submits contact details; server creates an `appointment` row with
   status `pending_payment` (this is what claims the slot against the
   exclusion constraint) and creates a Stripe PaymentIntent for the deposit.
4. Customer completes payment (Stripe Payment Element, possibly with a 3DS
   challenge).
5. On payment success, appointment moves to `confirmed`. Customer sees a
   confirmation page and receives a confirmation email with a
   signed manage-booking link.

### J2 — Failure path: payment declined
1. Same as J1 through step 4, but the PaymentIntent fails (card declined,
   insufficient funds, 3DS abandoned).
2. Appointment stays `pending_payment`. Customer is shown the decline reason
   Stripe returns and can retry with a different payment method against the
   *same* appointment row (not a new slot claim) while it hasn't expired.
3. If no successful payment arrives within the hold window — **15 minutes,
   a configuration value, not a hard-coded constant; see `09-decision-log.md`
   D-0011 for why this specific number is a provisional, pilot-dependent
   guess rather than a validated figure** — a scheduled job transitions the
   appointment to `cancelled` (`cancelled_by = system`), releasing the slot.

### J3 — Failure path: double-booking race
1. Two customers view the same open slot and both submit a booking request
   within the same short window.
2. Both requests attempt to insert an `appointment` row with an overlapping
   `appointment_range` for the same `resource_id`/`tenant_id`.
3. The database's exclusion constraint (D-0007) allows exactly one insert to
   succeed. The second request's insert raises SQLSTATE `23P01`.
4. The API translates that into `409 Conflict` / `SLOT_ALREADY_BOOKED` for
   the losing request — never a 500 — and the frontend re-fetches available
   slots so the customer can pick another time.

### J4 — Post-appointment: no-show
1. Appointment's scheduled end time passes with status still `confirmed`.
2. Owner marks it `no_show` from the dashboard (MVP: no automatic no-show
   detection — an explicit owner action).
3. Deposit stays captured and is forfeited per the studio's configured
   policy (D-0006 — no further Stripe call needed).
4. No balance charge is attempted for a no-show (balance only applies to an
   attended appointment) — see FR table.

### J5 — Post-appointment: attended, balance charged automatically
1. Owner marks the appointment `completed`.
2. If the studio's policy is auto-charge, the system attempts an off-session
   charge for the remaining balance against the card saved at deposit time.
3. Success → a `payments` row (`type = balance`, `status = succeeded`);
   owner dashboard reflects balance as paid.
4. **Failure path (expected, not an edge case):** the off-session charge is
   declined or requires authentication that can't be completed off-session.
   The `payments` row is `failed`; the owner is shown a clear
   "collect in person" fallback action (J6) rather than a silent failure.

### J6 — Post-appointment: balance marked paid in person
1. Owner marks the balance as paid manually (cash, e-transfer, in-studio
   terminal — outside bookslot).
2. A `payments` row (`type = balance`, `status = paid_manually`) is recorded
   for the owner's own reconciliation view — no Stripe call.

### J7 — Failure path: artist cancels
1. Owner or staff cancels a `confirmed` appointment before its start time.
2. Appointment → `cancelled` (`cancelled_by = studio`), freeing the slot.
3. Deposit disposition is a studio decision, not an automatic rule: the
   owner is prompted to refund (full/partial) or retain, and the system
   issues the corresponding Stripe refund only on explicit owner action.
4. Customer is notified of the cancellation and, if refunded, sees a refund
   confirmation.

### J8 — Failure path: studio refunds a cancelled/disputed booking
1. Owner triggers a refund (from J7, or independently for a confirmed
   booking within a studio-configured cancellation window).
2. A `refunds` row is created against the original deposit `payments` row;
   Stripe processes the refund; the payment's status moves to `refunded` or
   `partially_refunded`.
3. This is recorded in the audit trail (who, when, how much) because a
   refund decision is exactly the kind of evidence a later Stripe dispute
   may need.

### J9 — Failure path: webhook arrives late or twice
1. Stripe delivers a `payment_intent.succeeded` (or similar) webhook.
2. The event's Stripe event ID is checked against `stripe_webhook_events`
   before any state change is applied.
3. **Late arrival:** if the appointment's status already reflects the
   outcome (e.g. a synchronous confirmation already moved it to `confirmed`
   before the webhook arrived), the webhook handler is a no-op beyond
   recording the event — it must not re-trigage side effects (e.g. a second
   confirmation email).
4. **Duplicate delivery:** if the event ID has already been recorded as
   processed, the handler returns success immediately without reprocessing.
5. Webhook signature is verified before any of the above — an unsigned or
   badly-signed payload is rejected before it reaches business logic.

### J10 — Post-appointment: rebooking prompt
1. A scheduled job fires once after an appointment reaches `completed`,
   sending a rebooking message with a link back to the public booking page.
2. This is a fire-once notification — not sent for `no_show` or `cancelled`
   appointments. **Confirmed by ruling (Session 5, D-0014):** a no-show does
   **not** trigger the automatic prompt, full stop — not a placeholder
   default awaiting pilot data. An owner retains a separate, manual action
   (FR-23) to re-invite a specific no-show customer at their own discretion,
   so the capability to re-engage exists without an automatic system nudge
   applied indiscriminately to every no-show.

## Functional requirements

Each is marked **MVP** or **Paid** per the split already committed in
`01-scope-and-non-goals.md` — this section does not expand that scope.

| ID | Requirement | Journey | Tier |
|---|---|---|---|
| FR-01 | Public booking page lists a tenant's active services with name, duration, price, and deposit amount/percentage | J1 | MVP |
| FR-02 | Public booking page shows only genuinely available slots for a chosen service/staff, computed from working hours, exceptions, existing appointments, and buffer — never a slot that would violate the exclusion constraint | J1, J3 | MVP |
| FR-03 | Submitting a booking claims the slot (creates a `pending_payment` appointment) before payment is attempted, and a losing concurrent request receives `409 SLOT_ALREADY_BOOKED`, not a 500 | J1, J3 | MVP |
| FR-04 | Deposit is captured via Stripe at booking submission; the same payment method is saved for a later off-session balance charge | J1 | MVP |
| FR-05 | A `pending_payment` appointment that never completes payment within the hold window is automatically cancelled, releasing the slot | J2 | MVP |
| FR-06 | Automated reminders are sent at owner-configurable intervals before the appointment (illustrative default ~7 days/~24 hours/~2 hours per `01`) **[UNVAL — cadence effectiveness is R-04, unmeasured]** | — | MVP |
| FR-07 | Owner can mark an appointment `completed` or `no_show` | J4, J5 | MVP |
| FR-08 | No-show disposition (deposit forfeit vs. refund) follows the studio's configured policy, not a silent platform default | J4 | MVP |
| FR-09 | On `completed`, the remaining balance is either auto-charged off-session or left for the owner to mark paid in person, per studio configuration | J5, J6 | MVP |
| FR-10 | A failed automatic balance charge surfaces a clear "collect in person" fallback action to the owner — never a silent failure | J5 | MVP |
| FR-11 | Owner/staff can cancel a `confirmed` appointment before its start time; deposit refund is an explicit owner decision, not automatic | J7 | MVP |
| FR-12 | Owner can issue a full or partial refund against a captured deposit at any point that a studio policy or dispute requires it | J7, J8 | MVP |
| FR-13 | Every Stripe webhook is deduped by event ID and signature-verified before any state change | J9 | MVP |
| FR-14 | A one-time rebooking prompt is sent after a `completed` appointment, linking back to the public booking page | J10 | MVP |
| FR-15 | Owner dashboard shows upcoming appointments, deposit/balance status per appointment, and a basic no-show count, scoped strictly to the owner's own tenant | all | MVP |
| FR-16 | Staff can view only their own upcoming bookings at MVP. A studio-level toggle to widen this to all-staff-visible is a real, named capability, not a vague future maybe — but it is **Paid tier**, not built at MVP | — | MVP default (own bookings only); the widening **toggle** is Paid — see D-0013 |
| FR-17 | Platform admin can access cross-tenant data for support/ops purposes only through an explicit, narrow, audited path — never the same query path an owner/staff request uses | — | MVP (required by D-0005's tenancy model, even though no admin UI is in scope this session) |
| FR-18 | A studio's customer can request export or erasure of their own personal data; erasure anonymizes the customer record in place rather than deleting historical appointment/payment rows | — | MVP (06's GDPR-erasure-equivalent requirement; see `04-data-model.md`) |
| FR-19 | Waitlist / automatic slot-fill on cancellation | — | Paid (per `01`) |
| FR-20 | Analytics beyond a basic no-show count (trends, revenue forecasting, busy-slot analysis) | — | Paid (per `01`) |
| FR-21 | Custom branding / custom domain for the booking page | — | Paid (per `01`) |
| FR-22 | Package/membership pricing (prepaid bundles) | — | Paid (per `01`) — **and schema is deliberately not designed to anticipate this; see the unvalidated-assumptions note below** |
| FR-23 | Owner can manually re-invite a specific customer to book again (send that customer a link back to the public booking page as a deliberate, one-off action) — independent of, and available regardless of, whether that customer's last appointment was a no-show | J10 | MVP — **added Session 5, D-0014** |

## Non-functional requirements

| ID | Requirement | Why it's load-bearing here |
|---|---|---|
| NFR-01 | The public booking page is server-side rendered with meaningful HTML at first response (not a client-rendered shell) | This is the concrete reason `03-architecture.md` chose a decoupled Nuxt frontend over Inertia — a customer reached via an Instagram bio link on mobile data bounces if the page is slow or blank-then-hydrated |
| NFR-02 | All appointment-time arithmetic uses the tenant's stored IANA timezone with zone-aware conversion, never a fixed UTC offset | Required for DST-crossing appointments to compute correct absolute ranges (D-0007) |
| NFR-03 | Two concurrent booking requests for an overlapping window on the same resource can never both succeed; the losing request must receive a defined, non-500 error | D-0007; this is the one correctness property `01`'s Definition of MVP-complete calls out as needing dedicated testing |
| NFR-04 | Every Stripe webhook handler is idempotent under both duplicate delivery and out-of-order/late delivery | D-0006/06; required because Stripe does not guarantee exactly-once, in-order delivery |
| NFR-05 | Cross-tenant data isolation holds even against a raw query or a queued job missing tenant context (fail closed, not fail open) | D-0005; the one genuinely new architectural risk per `06` |
| NFR-06 | No formal uptime SLA is committed at MVP | Stated explicitly as *not yet decided*, not fabricated — there is no real pilot or paying customer yet to set an SLA against (00). Basic availability monitoring is expected; a numeric target is an open question, not an omission |

## Requirements explicitly dependent on unvalidated business assumptions

Flagged per this session's instruction not to design schema that only makes
sense if an unvalidated assumption holds. None of these are treated as
settled:

- **Reminder cadence (FR-06):** the specific intervals (~7d/~24h/~2h) are
  illustrative defaults, not a validated effective cadence (R-04). The
  schema must make cadence **owner-configurable per studio**, not hard-coded,
  precisely because the default itself is unvalidated.
- **Deposit type (fixed vs. percentage, FR-01/04):** both are supported
  because `01` doesn't commit to one, not because a pilot has told us which
  studios actually prefer — kept as a per-service choice, not a platform-wide
  default.
- **No-show disposition policy (FR-08):** modeled as studio-configured
  because `00` explicitly does not assume a single forfeiture rule works
  across studios.
- **Package/membership pricing (FR-22):** explicitly *not* anticipated in
  the schema (no bundle/credit tables, no multi-appointment linkage) per this
  session's instruction — building for it now would be designing against a
  pricing model `01` lists only as a possible future paid tier, not a
  commitment.
- **Whether a no-show customer still gets a rebooking prompt (J10):**
  resolved by ruling, Session 5 — see D-0014. No longer unvalidated.

## Resolved by ruling (Session 5) — no longer open

- **FR-16 — staff visibility into other staff's bookings within the same
  studio.** Resolved: own bookings only at MVP; a widening toggle exists as
  a named Paid-tier feature. See `09-decision-log.md` D-0013.
- **J10 — no-show rebooking prompt default.** Resolved: no automatic prompt;
  a manual re-invite capability (FR-23) exists instead. See D-0014.

## Data classification (sketch)

| Data | Classification | Notes |
|---|---|---|
| Customer name, email, phone | PII | Subject to FR-18 export/erasure |
| Appointment date/time/service/price | Business record | Retained even after customer anonymization (studio's own accounting) |
| Stripe PaymentIntent/charge/refund IDs, amounts | Payment metadata | Never raw card data (PCI scope minimization, per `06`) |
| Webhook raw payloads | Payment metadata / dispute evidence | Retained for a bounded window, see `04-data-model.md` |
| Studio owner/staff credentials | Auth secret | Hashed, never logged |

## Deferred (unchanged from Session 0/1's stub — not owned by this session)

- Integration requirements for a specific email/SMS provider contract.
- Exact numeric minimum notice period for booking/cancellation (a studio
  policy value, not a platform constant) — schema supports it as
  configuration; the actual default value is a product decision for a later
  session.
