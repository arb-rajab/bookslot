# API / Event Contracts
> Purpose: the interface others depend on.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-24 (Session 2 — Requirements and Data Model)

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
| `POST /api/owner/stripe/connect/onboarding-link` | Start/resume Stripe Connect Express onboarding |

### Staff (authenticated, tenant-scoped, narrower than owner)

| Method & path | Purpose |
|---|---|
| `GET /api/staff/appointments?from=&to=` | Own upcoming bookings (FR-16 — scope of "own" vs. "all staff" is the open question in `02`/`04`) |

### Platform admin (authenticated, cross-tenant, separate role per D-0005)

| Method & path | Purpose |
|---|---|
| `GET /api/admin/tenants/{id}/appointments` | Support/ops lookup, via the explicit `BYPASSRLS`-equivalent path, never the owner-facing query path |

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
  "customer": { "name": "…", "email": "…", "phone": "…" }
}
```

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

## Stripe webhooks consumed

| Webhook | Mutates |
|---|---|
| `payment_intent.succeeded` | `payments.status → succeeded`; if it's a `deposit` payment, `appointments.status: pending_payment → confirmed` (idempotent against a synchronous confirmation already having done this — J9) |
| `payment_intent.payment_failed` | `payments.status → failed`; records `failure_code` |
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
