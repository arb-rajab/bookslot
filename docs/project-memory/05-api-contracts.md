# API / Event Contracts
> Purpose: the interface others depend on.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-24 (Session 2 — Requirements and Data Model; amended Session 5 — mandate acceptance field added, FR-16 scope resolved; amended Session 6 — reconciled against D-0009/D-0012/D-0014, webhook coverage for the off-session balance charge clarified)

## Amendment (Session 6, 2026-08-24) — reconciliation after a full contradiction audit

A full audit (this session) checked this file against every decision made
since it was written in Session 2. Findings and fixes, each a genuine gap
found by the audit, not a cosmetic pass:

- **Platform-admin endpoint row corrected.** It described the admin path as
  "the explicit `BYPASSRLS`-equivalent path" — factually superseded by
  D-0009 (Session 3), which revised that mechanism to tenant impersonation
  under the ordinary `bookslot_app` role plus an app-layer check. Fixed
  below, not left to drift further now that this file is back in scope.
- **Service-management endpoints given a real detailed shape** for the first
  time, reflecting D-0012's required (no-default) `buffer_before_minutes`/
  `buffer_after_minutes` fields. **Written against both-configurable
  (before and after) — the broader case** — because D-0008's separate,
  still-open ruling (whether MVP restricts buffer to "after only" or allows
  both) is not decided; see the inline flag on the new endpoint below and
  `12-session-handoff.md` for the tracked open item. If that ruling narrows
  scope later, this endpoint's fields don't change shape — only an added
  validation rule (e.g., rejecting a non-zero `buffer_before_minutes`) would
  follow, not a contract redesign.
- **Manual re-invite endpoint (FR-23/D-0014) defined** for the first time —
  previously a decided capability with no spec.
- **`mandate_accepted`/`mandate_template_version` (D-0015(b)) confirmed
  present and correct** on `POST /api/tenants/{slug}/bookings` — added
  correctly in Session 5, carries a template version identifier as
  required, the mandate text itself stays pending per D-0015(a). No change
  needed here.
- **Webhook coverage for the off-session balance charge (D-0006)
  clarified.** The `payment_intent.succeeded`/`payment_intent.payment_failed`
  rows already covered a `balance`-type PaymentIntent structurally (both are
  looked up by `stripe_payment_intent_id`, not hard-coded to `deposit`), but
  didn't say so — this was a documentation gap, not a missing webhook type
  or a functional bug. Made explicit below, including the idempotency
  relationship to endpoint 6's own synchronous response.
- **One gap found, not fixed:** `POST /api/bookings/{id}/confirm-payment` is
  a public, unauthenticated endpoint addressed only by an unscoped
  `appointment_id`, with neither a `{slug}` (D-0009's public-path
  mechanism) nor a signed token (D-0009's manage-booking-link mechanism) to
  derive tenant context from before an RLS-protected lookup can run. D-0009
  covers exactly two public-path tenant-resolution mechanisms and this
  endpoint uses neither — the mechanism for it is genuinely unspecified,
  not merely undocumented. Per this session's instruction not to invent a
  missing detail in a later decision, this is raised as an open item (see
  `12-session-handoff.md`), not resolved here.

## Amendment (Session 5, 2026-08-24) — mandate field added, staff scope resolved

Two changes this session, both by explicit ruling (`09-decision-log.md`):

- **`POST /api/tenants/{slug}/bookings`** (endpoint 2 below) gains
  `mandate_accepted` and `mandate_template_version` on the request body —
  D-0015(b). This was an open item since Session 3 (D-0010); the mandate's
  actual wording/copy stays deferred (D-0015(a)), unaffected by this change.
- **The staff endpoint's scope** (own bookings vs. all-staff) is resolved,
  not open — D-0013: own bookings only at MVP, a widening toggle is Paid.

This supersedes Session 0/1's stub with a sketch proving `04-data-model.md`
is usable end to end — not a full spec. Full versioning policy, pagination,
and rate-limit numbers are still future-session work (see Deferred below).

## Endpoint list, grouped by actor

### Public (unauthenticated — customer-facing)

| Method & path | Purpose | MVP journey |
|---|---|---|
| `GET /api/tenants/{slug}/services` | List active services for a studio | J1 |
| `GET /api/tenants/{slug}/availability?service_id=&staff_id=&from=&to=` | Computed bookable slots (derived, per `04`) | J1, FR-02 |
| `POST /api/tenants/{slug}/bookings` | Create a `pending_payment` appointment (claims the slot) | J1, J3 |
| `POST /api/bookings/{id}/confirm-payment` | Confirm/retry the deposit PaymentIntent for an existing `pending_payment` booking | J1, J2 |
| `GET /api/bookings/manage/{token}` | Look up a booking via the signed manage-booking link (no login) | J1, J7 |
| `POST /api/bookings/manage/{token}/cancel` | Customer-initiated cancellation | J7 |

### Studio owner (authenticated, tenant-scoped)

| Method & path | Purpose |
|---|---|
| `GET /api/owner/appointments?from=&to=&status=` | Dashboard calendar/list view |
| `PATCH /api/owner/appointments/{id}/status` | Mark `completed` / `no_show` / `cancelled` |
| `POST /api/owner/appointments/{id}/refund` | Issue a full/partial refund on the deposit |
| `POST /api/owner/appointments/{id}/balance/charge` | Trigger the off-session balance charge |
| `POST /api/owner/appointments/{id}/balance/mark-paid` | Record a manual (in-person) balance payment |
| `GET/POST/PATCH /api/owner/services` | Manage services + deposit config |
| `GET/POST/PATCH /api/owner/staff` | Manage staff/resources |
| `GET/POST/PATCH /api/owner/staff/{id}/working-hours` | Recurring availability |
| `POST /api/owner/staff/{id}/availability-exceptions` | One-off blocks/holidays |
| `POST /api/owner/customers/{id}/erasure` | FR-18 erasure request |
| `GET /api/owner/customers/{id}/export` | FR-18 export |
| `POST /api/owner/customers/{id}/re-invite` | FR-23/D-0014: manually re-invite a specific customer to book again — **defined Session 6**, see below |
| `POST /api/owner/stripe/connect/onboarding-link` | Start/resume Stripe Connect Express onboarding |

### Staff (authenticated, tenant-scoped, narrower than owner)

| Method & path | Purpose |
|---|---|
| `GET /api/staff/appointments?from=&to=` | Own upcoming bookings only, at MVP — resolved, not open (FR-16, D-0013). A studio-level toggle to widen this to all-staff-visible is a named Paid-tier feature, not built here |

### Platform admin (authenticated, cross-tenant, separate role per D-0005)

| Method & path | Purpose |
|---|---|
| `GET /api/admin/tenants/{id}/appointments` | Support/ops lookup. **Corrected, Session 6** — authenticates as the ordinary `bookslot_app` role and impersonates tenant `{id}` by setting `app.current_tenant_id` to it, gated by an app-layer check that the caller is `role = 'platform_admin'` (D-0009); never a `BYPASSRLS` role (that's reserved exclusively for `bookslot_migrator`'s offline use), and never the owner-facing query path |

## Core endpoints, detailed

### 1. `GET /api/tenants/{slug}/availability`

**Request (query params):** `service_id` (uuid, required), `staff_id` (uuid,
optional — omit to search across all staff who offer the service),
`from`/`to` (date, required, bounded to a sane lookahead window — exact
number of weeks is a product decision, not fixed here).

**Response `200`:**
```json
{
  "slots": [
    { "staff_id": "…", "starts_at": "2026-09-10T17:00:00Z", "ends_at": "2026-09-10T18:00:00Z" }
  ]
}
```
Timestamps are UTC ISO-8601; the frontend renders them in the tenant's
timezone (`GET /api/tenants/{slug}` — not detailed here — returns the
tenant's IANA zone for this purpose).

**Errors:** `404` unknown `slug`/`service_id`; `422` invalid date range.

### 2. `POST /api/tenants/{slug}/bookings`

**Request:**
```json
{
  "service_id": "…", "staff_id": "…",
  "starts_at": "2026-09-10T17:00:00Z",
  "customer": { "name": "…", "email": "…", "phone": "…" },
  "mandate_accepted": true,
  "mandate_template_version": "…"
}
```
`mandate_accepted`/`mandate_template_version` — **added Session 5, D-0015(b)**.
`mandate_accepted` must be `true` (a `422 VALIDATION_FAILED` otherwise);
`mandate_template_version` echoes back which version of the mandate text the
customer was shown, and is stored verbatim into
`payment_mandates.mandate_template_version`. How the client obtains the
mandate text/version to display before submitting — and the text's actual
wording — is not decided here; that's the still-deferred half of D-0015(a).

**Response `201`** (slot claimed, deposit PaymentIntent created):
```json
{
  "appointment_id": "…",
  "status": "pending_payment",
  "deposit": { "amount": 5000, "currency": "usd", "client_secret": "pi_…_secret_…" },
  "manage_token": "…"
}
```
`client_secret` is handed to the frontend to complete payment via Stripe's
Payment Element — the server never sees card data (06's PCI scope
minimization).

**Errors:**
- `409 { "error": "SLOT_ALREADY_BOOKED" }` — the exclusion constraint
  rejected the insert (J3, D-0007). The frontend's defined response is to
  re-fetch availability, not to retry the same request.
- `422 { "error": "VALIDATION_FAILED", "fields": {...} }` — bad input.
- `404` unknown `slug`/`service_id`/`staff_id`.

### 3. `POST /api/bookings/{id}/confirm-payment`

Used both for the initial payment attempt (if not resolved synchronously by
step 2's client_secret flow) and for a retry after a decline (J2).

**Response `200`:** `{ "status": "confirmed" }` on success, or
`{ "status": "pending_payment", "last_payment_error": { "code": "card_declined", "message": "…" } }`
on a synchronous decline — the customer sees this and can retry with a
different card, per J2.

**Errors:** `409 { "error": "BOOKING_EXPIRED" }` if the hold window already
lapsed and a scheduled job already moved the appointment to `cancelled` —
distinct from `SLOT_ALREADY_BOOKED`, since this is "your own hold expired,"
not "someone else won the race."

### 4. `PATCH /api/owner/appointments/{id}/status`

**Request:** `{ "status": "no_show" }` or `{ "status": "completed" }` or
`{ "status": "cancelled", "reason": "…" }`.

**Response `200`:** the updated appointment. If `completed` and the
studio's balance policy is auto-charge, this endpoint's response also
reflects the resulting balance-payment attempt outcome (see endpoint 6)
rather than requiring a second round trip.

**Errors:** `409 { "error": "INVALID_STATUS_TRANSITION" }` — e.g. attempting
to mark `completed` a booking that's still `pending_payment` (04's state
machine doesn't allow it); `403` if the appointment doesn't belong to the
caller's tenant (defense-in-depth on top of RLS — this should be
unreachable, and its being reachable is itself a signal worth alerting on).

### 5. `POST /api/owner/appointments/{id}/refund`

**Request:** `{ "amount": 5000 }` (omit for a full refund of the deposit).

**Response `200`:** the created `refunds` record and updated payment
status (`refunded` or `partially_refunded`).

**Errors:** `422` if `amount` exceeds the remaining refundable balance on
the deposit payment; `409` if the deposit payment isn't in a refundable
state (e.g. already fully refunded).

### 6. `POST /api/owner/appointments/{id}/balance/charge`

**Response `200` (success):** `{ "status": "succeeded", "payment_id": "…" }`.

**Response `200` (expected failure, J5's second half):**
```json
{
  "status": "failed",
  "failure_code": "authentication_required",
  "fallback_action": "mark_paid_manually"
}
```
Deliberately a `200` with a `failed` status, not a `4xx/5xx` — a declined
off-session charge is an expected, handled business outcome (J5), not a
malformed request or a server error. The response tells the frontend
exactly which fallback UI to show (J6), rather than the frontend inferring
it from an HTTP status code.

### 7. `POST /api/owner/staff/{id}/availability-exceptions`

**Request:** `{ "date": "2026-12-25", "is_available": false, "reason": "Studio closed" }`.

**Response `201`:** the created exception. Availability computation
(endpoint 1) picks this up on the next read — no cache invalidation step
needed since slots are derived, not materialized (per `04`).

**Errors:** `422` if `date` is in the past.

### 8. `POST /api/owner/services` (and `PATCH /api/owner/services/{id}`) — detailed Session 6, per D-0012

**Request (`POST`, creation):**
```json
{
  "name": "…",
  "duration_minutes": 90,
  "price_amount": 20000,
  "currency": "usd",
  "deposit_type": "fixed",
  "deposit_fixed_amount": 5000,
  "buffer_before_minutes": 0,
  "buffer_after_minutes": 15
}
```
`buffer_before_minutes` and `buffer_after_minutes` are **required with no
default** on creation, per D-0012 — omitting either is a validation error,
not a silent zero. **Written against both-configurable (before and after)
deliberately** — D-0008's separate, still-open ruling on whether MVP
restricts buffer to "after only" is not decided (see `12-session-handoff.md`);
this endpoint doesn't anticipate that ruling's outcome by narrowing the
shape itself. If a future ruling restricts scope, the fix is an added
validation rule (e.g., rejecting a non-zero `buffer_before_minutes`), not a
field removal.

**Response `201`:** the created service, including both buffer fields as
stored.

**Errors:**
- `422 { "error": "VALIDATION_FAILED", "fields": { "buffer_before_minutes": "required" } }`
  (or `buffer_after_minutes`) — missing on creation.
- `422` if either buffer value is negative or exceeds `1440` (mirrors `04`'s
  `CHECK (... >= 0 AND ... <= 1440)`).
- `422` for the pre-existing deposit-type/amount cross-field validation
  (unchanged from Session 2).

**Request (`PATCH`, update):** any subset of the above fields. Buffer
fields are optional on update — D-0012's "required" rule applies only to
creation, so an update that doesn't touch buffer leaves the service's
existing values unchanged. A buffer value that *is* provided on update is
validated by the same bounds as creation.

### 9. `POST /api/owner/customers/{id}/re-invite` — detailed Session 6, per D-0014/FR-23

**Request:** `{}` — no configurable fields at MVP; the action itself (send
this specific customer a link back to the public booking page) is the
entire intent, independent of whether their last appointment was a
no-show (FR-23 is explicit that this isn't gated on no-show status).

**Response `202`:** `{ "status": "queued", "channel": "email" }` — accepted
for asynchronous delivery via the same notification-sending
infrastructure as reminders, sent immediately rather than on a schedule.

**Errors:** `404` unknown `customer_id` (tenant-scoped, per the owner's own
tenant).

**Not resolved by this endpoint definition — a proposed follow-up, not
made here:** `04-data-model.md`'s `notification_deliveries.purpose` CHECK
list only enumerates `reminder_7d, reminder_24h, reminder_2h,
rebooking_prompt` — there is no `manual_reinvite` (or equivalent) value to
record this send against. Defining this endpoint's request/response shape
doesn't require deciding that column-level detail, so it isn't added here;
`04` wasn't in this session's authorized scope. See `12-session-handoff.md`.

## Stripe webhooks consumed

| Webhook | Mutates |
|---|---|
| `payment_intent.succeeded` | `payments.status → succeeded`, looked up by `stripe_payment_intent_id` regardless of `type` — this already covers a `balance`-type PaymentIntent structurally, not just `deposit`, though Session 2's original wording only called out the `deposit` case explicitly (clarified Session 6). For a `deposit` payment: additionally `appointments.status: pending_payment → confirmed` (idempotent against a synchronous confirmation already having done this — J9). For a `balance` payment (D-0006's off-session charge, triggered synchronously by endpoint 6): this webhook is expected to arrive *after* endpoint 6's own synchronous response already recorded `succeeded` — it must be a no-op beyond confirming the already-recorded state, the same idempotency discipline J9 already establishes for deposits |
| `payment_intent.payment_failed` | `payments.status → failed`; records `failure_code`. Applies to both `deposit` (J2's decline/retry path) and `balance` (J5's off-session decline, endpoint 6) payment types, by the same `stripe_payment_intent_id` lookup — idempotent against endpoint 6's synchronous failure response for the balance case, same reasoning as above (clarified Session 6) |
| `charge.refunded` | `refunds.status → succeeded`; `payments.status → refunded`/`partially_refunded` |
| `charge.dispute.created` / `charge.dispute.closed` | `booking_events` audit entry (dispute evidence per 06); does not itself mutate appointment/payment status — a dispute is tracked, not auto-resolved |
| `account.updated` (Connect) | `tenants.stripe_onboarding_status` |

Every webhook handler: (1) verifies `Stripe-Signature`, (2) inserts into
`stripe_webhook_events` with `ON CONFLICT (stripe_event_id) DO NOTHING` and
checks whether the insert actually happened before doing anything else
(NFR-04/J9), (3) applies the mutation only if the insert succeeded, (4)
records `processed_at` on success or `processing_error` on failure so a
failed handler can be identified and retried without reprocessing an event
that already succeeded.

## SSR vs. client-side fetch (the reason for the decoupled Nuxt frontend)

| Surface | Rendering | Why |
|---|---|---|
| Public booking page (service list, initial availability) | **SSR** — data fetched server-side at render time (endpoints 1 above, plus the tenant/services lookup) | This is `03-architecture.md`'s D-0002 justification made concrete: a customer reached via a social-bio link needs real HTML and Core Web Vitals on first response, not a blank shell that then fetches |
| Slot picker interaction after initial load (re-fetching availability as the customer changes date/service) | Client-side fetch | No SEO value in a specific slot selection; SSR-ing every interaction would add latency with no benefit |
| Booking submission, payment confirmation | Client-side (Stripe Payment Element is inherently a client-side flow) | Card entry cannot happen server-side without breaking PCI scope minimization (06) |
| Owner/staff dashboard (all of it) | Client-side fetch behind auth | No SEO surface — it's an authenticated internal tool, the same rendering model `03` explicitly did *not* need Nuxt's SSR for |
| Manage-booking link (`GET /api/bookings/manage/{token}`) | SSR for the initial page load (still a public, unauthenticated, link-shared surface a customer might open from an email/SMS on mobile) | Same SEO/performance reasoning as the booking page itself — this page is also reached cold, off-platform |

## Deferred to a future session

- Full endpoint/schema reference (this is a sketch of 5–8 core endpoints
  plus a list, not an exhaustive contract).
- Versioning/deprecation policy.
- Pagination conventions for list endpoints (`GET /api/owner/appointments`
  above will need one before it's real).
- Rate limiting, especially on the public booking endpoints (unauthenticated
  by nature — abuse/spam-booking protection is a real concern FR-03's
  hold-window design doesn't fully address on its own).
- Auth token mechanics (session vs. API token, refresh, expiry) for owner/
  staff/admin — `02-requirements.md` establishes *who* authenticates, not
  *how* yet.
