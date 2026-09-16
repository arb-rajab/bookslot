# API / Event Contracts
> Purpose: the interface others depend on.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-26 (Session 2 — Requirements and Data Model; amended Session 5 — mandate acceptance field added, FR-16 scope resolved; amended Session 6 — reconciled against D-0009/D-0012/D-0014, webhook coverage for the off-session balance charge clarified; amended Session 7 — endpoint 3 redesigned around D-0021's booking-scoped token, closing the tenant-context gap Session 6 raised and didn't fix; amended Session 9 — three endpoints actually implemented for the first time, with two real gaps found and raised rather than invented; amended Session 10 — both Session 9 gaps closed: auth wired (D-0029), booking creation built for real (D-0030); amended Session 16 — endpoint 1 (availability) built for real (D-0039), first real frontend consumer, a real CSRF-on-public-endpoints finding recorded (D-0041); amended Session 17 — the owner appointment list/status endpoints built (D-0042), a serious pre-existing owner/staff re-authentication bug found and fixed (D-0043); amended Session 21 — endpoint 3b (`POST /api/bookings/manage/{token}/cancel`, customer-initiated cancellation) built, closing this row from "sketch, not built" to real (D-0052))

## Amendment (Session 17, 2026-08-26) — owner dashboard endpoints built; a real, previously-undetected owner/staff auth bug found and fixed

**Objective, for context:** close the one piece of the critical workflow (`00-project-brief.md`, `01-scope-and-non-goals.md`'s Definition of MVP complete) that had no owner-facing surface at all — a studio owner had no way to see a booking or mark attendance. Built `GET /api/owner/appointments` and `PATCH /api/owner/appointments/{id}/status` (`completed`/`no_show` only — see D-0042 for why `cancelled` was deliberately left out), plus the first real owner-dashboard frontend page (`frontend/app/pages/owner/index.vue`).

**The real finding, discovered only by testing this against a live `php artisan serve` process (not just Pest) for the first time:** a returning owner/staff session could never re-authenticate on any request after the first — a bug present since D-0029 (Session 10) in every owner/staff route this codebase has ever built, invisible to every prior session's Pest-only verification. Full root cause, two rejected fix attempts, and the actual fix (one consolidated `auth.tenant` middleware, session-based tenant resolution): **D-0043**. This is the single most significant finding of this session — everything built on top of it (including this session's own new endpoints) was unreachable by a real browser or a real second HTTP request until it was fixed.

**Verified end to end against the real running API, not just the test suite:** a real login → `GET /api/owner/appointments` (real customer/service/staff names, real deposit status) → `PATCH .../status` (real `completed` transition, verified via direct database read that a `booking_events` row was created and no `payments`/`refunds` row was) → the same invalid-transition case rejected with `409`, all via real HTTP requests replicating the frontend's exact CORS/CSRF/cookie protocol — the same Claim-A methodology Session 16 established. Repeated with both `SESSION_DRIVER=redis` and `SESSION_DRIVER=file` locally to isolate the auth-ordering bug from the local Redis/predis setup D-0041 already flagged as environment-specific.

**A second, smaller finding fixed along the way:** any authenticated route hit by a client that doesn't send `Accept: application/json` (a bare `curl`, not this project's own frontend/tests) crashed `500` instead of returning a clean `401` — Laravel's default `Authenticate::redirectTo()` tries to build a `route('login')` URL this API-only app has never had. Fixed in `bootstrap/app.php` (`redirectGuestsTo(fn () => null)` plus a structured `{"error": "UNAUTHENTICATED"}` renderer for `AuthenticationException`), found the same way as D-0043 — by hitting the real server, not by reading the code.

**Files touched (API-contract-relevant only — see the handoff for the complete list):** `app/Http/Controllers/Api/Owner/AppointmentController.php` (new), `app/Http/Middleware/AuthenticateTenantUser.php` (new, replaces the deleted `ResolveTenantFromAuthenticatedUser.php`), `app/Http/Controllers/Api/AuthController.php` (session now carries `tenant_id`), `bootstrap/app.php`, `routes/api.php`, `database/seeders/DatabaseSeeder.php` (a real owner login + demo appointments), `frontend/app/pages/owner/index.vue` (new), `tests/Feature/Api/OwnerAppointmentControllerTest.php` (new), this file, `09-decision-log.md` (D-0042, D-0043).

## Amendment (Session 16, 2026-08-26) — availability built; the first real frontend consumer; public endpoints found to still require Sanctum's CSRF cookie

**Objective, for context:** this session's actual deliverable was the Nuxt frontend (`frontend/`, see `03-architecture.md`'s own Session 16 amendment for the repo-layout decision) — the first customer-facing surface calling this API. Two real gaps surfaced by building and actually exercising that consumer, both closed this session rather than left as sketches:

- **Endpoint 1, `GET /api/tenants/{slug}/availability`, is now real** — see D-0039 (`09-decision-log.md`) for the full derived-slot algorithm as built (working hours + exceptions + buffered existing-appointment occupancy, timezone-projected per calendar date) and the two new provisional config decisions (`availability_max_lookahead_days` default 60, `availability_slot_increment_minutes` default 15). Response shape is unchanged from this file's original sketch (`{"slots": [{"staff_id","starts_at","ends_at"}]}`); `staff_id` omitted searches every active staff member (this schema still has no staff/service pivot, so every active staff member is treated as offering every service — unchanged from before this session).
- **A real, previously-unexercised finding: public/unauthenticated endpoints still require Sanctum's CSRF cookie when called from a configured stateful origin.** Found by actually issuing a cross-origin request from the frontend's own origin, not by reading the code — see D-0041 for the full decision (kept CSRF protection as-is; the frontend performs the real `GET /sanctum/csrf-cookie` → `X-XSRF-TOKEN` dance) and the options rejected. This applies to endpoint 2 (`POST .../bookings`) and endpoint 3 (`POST .../confirm-payment`) exactly as much as to the authenticated owner/staff/admin endpoints — not a new requirement, but the first time it was actually proven true for the public ones specifically.

**Verified end to end against the real running API, not just by test suite:** a real HTTP request sequence (services → availability → mandate → booking → confirm-payment), including the real CORS + CSRF cookie dance with `Origin: http://localhost:3000`, produced a real `pending_payment` → `confirmed` appointment, confirmed via a direct database read afterward. See `12-session-handoff.md`'s Session 16 amendment for the full verification account.

**Files touched this session (API-contract-relevant only — see the handoff for the complete list):** `app/Http/Controllers/Api/AvailabilityController.php` (new), `config/booking.php` (two new keys), `routes/api.php`, `tests/Feature/Api/AvailabilityControllerTest.php` (new), `frontend/` (new — the consumer), this file, `09-decision-log.md` (D-0038–D-0041).

## Amendment (Session 10, 2026-08-25) — both Session 9 gaps closed: auth wired, booking creation built

Both gaps Session 9 raised and deliberately did not close are closed this session, by ruling (see `09-decision-log.md` D-0029/D-0030/D-0031 for full reasoning):

- **Endpoint 2, `POST /api/tenants/{slug}/bookings`, is now real**, not just sketched — see its own section below for the shape as actually built, which differs from the original sketch in a few real ways: error responses now also include `404 { "error": "NOT_FOUND" }` (unknown `service_id`/`staff_id`) and `502 { "error": "PAYMENT_PROVIDER_UNAVAILABLE" }` (the Stripe call itself failed — D-0027/D-0030's transaction-boundary cleanup path); `422` responses use the shape `{ "error": "VALIDATION_FAILED", "fields": {...} }` globally now (see below), not per-endpoint. The mandate-contract gap is closed by `mandate_text` being **server-rendered** (`App\Mandates\MandateRenderer`, D-0030) rather than added as a client-facing field — the client-facing contract (`mandate_accepted`/`mandate_template_version`) is unchanged from D-0015(b), exactly as anticipated.
- **New public endpoint: `GET /api/tenants/{slug}/services/{service}/mandate`** — the pre-submission mandate display Session 9 found missing entirely. Returns `{ "template_version", "text", "balance_amount_disclosed" }`, rendered by the same `MandateRenderer` booking creation's storage path uses. `404 { "error": "NOT_FOUND" }` for an unknown/cross-tenant `service`.
- **New auth endpoints (D-0029):** `POST /api/tenants/{slug}/login` (owner/staff — runs behind the slug-resolution mechanism, since `users_tenant_email_unique` is per-tenant and the lookup needs to know which tenant first), `POST /api/admin/login` (platform_admin — no tenant resolution needed, since a `platform_admin` row is visible with no context set), `POST /api/logout` (shared by every role). All three: `{"email","password"}` in (login), `401 { "error": "INVALID_CREDENTIALS" }` on failure or a wrong-role credential match at the wrong entry point; success returns `{"user": {"id","role","tenant_id","name","email"}}`. Real Sanctum SPA (stateful/cookie) session auth — a valid CSRF token is required on every state-changing request from a configured frontend origin, verified by execution, not assumed (see D-0029).
- **A global `{"error": "VALIDATION_FAILED", "fields": {...}}` rendering rule** (`bootstrap/app.php`) now applies to every `api/*` endpoint's `ValidationException`, not per-controller — endpoint 8's `05`-documented shape (already written this way) is now what every endpoint actually returns, including the two above. Likewise a global `{"error": "NOT_FOUND"}` rule for `NotFoundHttpException` (covers both an unmatched route and any `findOrFail()` miss — Laravel's own exception handler unconditionally converts `ModelNotFoundException` into `NotFoundHttpException` before any custom `ModelNotFoundException`-typed renderer would ever run, found by executing this, not by reading the framework's docs).
- **A representative, real slice of owner/staff/admin endpoints is now built** — not all of `05`'s rows, by design (see D-0029): `POST /api/owner/services` (endpoint 8 below, unchanged shape, now with a real controller behind real auth), `GET /api/staff/appointments` (FR-16/D-0013 own-bookings-only, proven by test), `GET /api/admin/tenants/{tenant}/appointments` (D-0009's impersonation path, proven cross-tenant-safe by test). Every other owner/staff/admin row in the tables below is still just a contract, no controller — unblocked by D-0029, not yet built.

**Not resolved this session, carried forward:** real Stripe test-mode credentials were not available (see `12-session-handoff.md`) — `StripePaymentIntentGateway` is built and wired as the real default, but only exercised via a fake in this session's tests; the hold-window expiry scheduled job (D-0011's mechanism was already decided, no job enforces it yet); backfilling `payment_mandates.stripe_payment_method_id` once a payment method is actually known (D-0031); rate limiting on public endpoints (already named in Deferred below).

## Amendment (Session 9, 2026-08-24) — three endpoints implemented; an auth-mechanism gap and a mandate-contract gap raised, not closed

**What's now real, not just sketched:** `GET /api/tenants/{slug}/services`, `GET /api/bookings/manage/{token}`, and `POST /api/bookings/{token}/confirm-payment` (D-0021) are implemented and covered by Feature tests, the first controllers/routes in this repository. Two response shapes this file previously left undetailed are now fixed by what's actually built:

- **`GET /api/tenants/{slug}/services`** returns `{"services": [...]}`, each item carrying the fields FR-01 already names (`id`, `name`, `duration_minutes`, `price_amount`, `currency`, `deposit_type`, `deposit_fixed_amount`, `deposit_percentage_bps`, `buffer_before_minutes`, `buffer_after_minutes`), filtered to `is_active = true`, ordered by name. Unknown or soft-deleted slug: `404 { "error": "NOT_FOUND" }` — deliberately the same shape for both reasons a slug might not resolve, so neither is distinguishable from the response alone.
- **`GET /api/bookings/manage/{token}`** returns `{"appointment_id", "status", "starts_at", "ends_at"}` on success. This is a minimum-viable shape, not a final one — this file's own Deferred section already flags the full endpoint/schema reference as future work; whoever next has this endpoint in scope for real (customer-facing cancellation copy, related resources) should treat this shape as a floor, not a ceiling.
- **`INVALID_OR_EXPIRED_TOKEN` (404) is now used for `manage_booking`-purpose token failures too, not only `confirm_payment`'s** — D-0021 named this error code for its own endpoint specifically; this session's `App\Tenancy\SignedTenantToken` (D-0026) implements token verification as one mechanism serving every purpose, so a bad/wrong-purpose/expired token fails identically regardless of which purpose it was meant for. This is a natural, low-risk generalization of an already-decided error shape (same reasoning D-0021 itself used to generalize D-0009's token mechanism), not a new design decision — recorded here so it doesn't silently diverge from what D-0021's own text describes as endpoint-3-specific.

**Two real gaps found while implementing, raised rather than invented (see `12-session-handoff.md` for the full session report):**

- **Owner/staff/platform-admin auth mechanics remain undecided.** This file's own Deferred section already says so ("session vs. API token... `02` establishes who authenticates, not how yet") — this session confirms it's still true and that it blocks real controllers for `05`'s owner/staff/admin endpoint rows, not just their auth *detail*. No owner/staff/admin route was built this session for that reason.
- **`POST /api/tenants/{slug}/bookings`'s mandate fields have no field for the actual rendered text.** The request as specified (Session 5, D-0015(b)) carries `mandate_accepted` (bool) and `mandate_template_version` (string) but nothing carrying `payment_mandates.mandate_text` itself (D-0010: "the full rendered text the customer agreed to"). Building booking creation this session would have required either inventing that missing field's shape or inventing placeholder legal/consent content for a `NOT NULL`, dispute-evidentiary column — both are exactly what this session was told not to do. Booking creation was not built for that reason; this file's endpoint 2 is otherwise unchanged.

## Amendment (Session 7, 2026-08-24) — confirm-payment's tenant-context gap closed (D-0021)

Session 6 found, and deliberately did not fix, that `POST
/api/bookings/{id}/confirm-payment` had no way to derive tenant context
before its RLS-protected lookup — it matched neither of D-0009's two named
public-path mechanisms. Resolved this session as **D-0021**: the endpoint's
route and mechanism are redesigned around a new, purpose-scoped signed
token (`payment_confirmation_token`), generalizing D-0009's existing
token-based mechanism rather than inventing a third one. See endpoint 2 and
endpoint 3 below for the updated contract, and `09-decision-log.md` D-0021
for the full reasoning, rejected alternatives, and why this needed no
`04-data-model.md` change. Also this session: D-0024 formally settles the
buffer before/after-scope question endpoint 8 below had flagged as still
open (no contract change follows); D-0023 adds the `rebooking_invite`
`notification_deliveries.purpose` value endpoint 9 below had flagged as
missing.

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
| `GET /api/tenants/{slug}/services` | List active services for a studio — **built** | J1 |
| `GET /api/tenants/{slug}/services/{service}/mandate` | Pre-submission mandate text display, server-rendered — **built Session 10, D-0030** | J1 |
| `GET /api/tenants/{slug}/availability?service_id=&staff_id=&from=&to=` | Computed bookable slots (derived, per `04`) — **built Session 16, D-0039** | J1, FR-02 |
| `POST /api/tenants/{slug}/bookings` | Create a `pending_payment` appointment (claims the slot) — **built Session 10, D-0030** | J1, J3 |
| `POST /api/tenants/{slug}/login` | Owner/staff login (Sanctum SPA session) — **built Session 10, D-0029** | — |
| `POST /api/admin/login` | Platform-admin login, no tenant resolution needed — **built Session 10, D-0029** | — |
| `POST /api/logout` | Shared by every role — **built Session 10, D-0029** | — |
| `POST /api/bookings/{token}/confirm-payment` | Confirm/retry the deposit PaymentIntent for an existing `pending_payment` booking, keyed by the booking-scoped `payment_confirmation_token` (D-0021) — **not** a raw `appointment_id`, see endpoint 3 below | J1, J2 |
| `GET /api/bookings/manage/{token}` | Look up a booking via the signed manage-booking link (no login) | J1, J7 |
| `POST /api/bookings/manage/{token}/cancel` | Customer-initiated cancellation, bookkeeping only (no refund) — **built Session 21, D-0052**, see endpoint 3b below | J7 |

### Studio owner (authenticated, tenant-scoped)

| Method & path | Purpose |
|---|---|
| `GET /api/owner/appointments?from=&to=&status=` | Dashboard calendar/list view — **built Session 17, D-0042**, own tenant's appointments across every staff member (D-0013's own-bookings narrowing is a staff rule, not an owner one); response also carries a tenant-wide `no_show_count` (FR-15, D-0055, Session 25) |
| `PATCH /api/owner/appointments/{id}/status` | Mark `completed` / `no_show` — **`completed`/`no_show` built Session 17, D-0042**; `cancelled` deliberately not accepted by this endpoint yet, see D-0042 |
| `POST /api/owner/appointments/{id}/refund` | Issue a full/partial refund on the deposit |
| `POST /api/owner/appointments/{id}/balance/charge` | Trigger the off-session balance charge |
| `POST /api/owner/appointments/{id}/balance/mark-paid` | Record a manual (in-person) balance payment |
| `GET/POST/PATCH /api/owner/services` | Manage services + deposit config — **`POST` built Session 10 (D-0029), real auth-gated; `GET`/`PATCH` still unbuilt** |
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
| `GET /api/staff/appointments?from=&to=` | Own upcoming bookings only, at MVP — resolved, not open (FR-16, D-0013). A studio-level toggle to widen this to all-staff-visible is a named Paid-tier feature, not built here. **Built Session 10 (D-0029)**, real auth-gated, proven by test that a second staff member's appointments never appear |

### Platform admin (authenticated, cross-tenant, separate role per D-0005)

| Method & path | Purpose |
|---|---|
| `GET /api/admin/tenants/{id}/appointments` | Support/ops lookup. **Corrected, Session 6** — authenticates as the ordinary `bookslot_app` role and impersonates tenant `{id}` by setting `app.current_tenant_id` to it, gated by an app-layer check that the caller is `role = 'platform_admin'` (D-0009); never a `BYPASSRLS` role (that's reserved exclusively for `bookslot_migrator`'s offline use), and never the owner-facing query path. **Built Session 10 (D-0029)**, proven cross-tenant-safe by test |

## Core endpoints, detailed

### 1. `GET /api/tenants/{slug}/availability` — built Session 16, D-0039

**Request (query params):** `service_id` (uuid, required), `staff_id` (uuid,
optional — omit to search across all staff who offer the service — this
schema has no staff/service pivot yet, so every active staff member is
treated as offering every service), `from`/`to` (date, `Y-m-d`, required).
The lookahead window is bounded by `config('booking.
availability_max_lookahead_days')` (default 60 — a real, provisional
product decision now, D-0039, not left open) — a wider range returns `422
VALIDATION_FAILED`. Candidate slots are generated at `config('booking.
availability_slot_increment_minutes')` (default 15).

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

### 2. `POST /api/tenants/{slug}/bookings` — built Session 10, D-0030

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
`payment_mandates.mandate_template_version`. The client obtains the mandate
text/version to display before submitting from **`GET /api/tenants/{slug}/
services/{service}/mandate`** (built Session 10, D-0030) — `mandate_text`
itself is never sent by the client on this endpoint; it's rendered
server-side by the same `App\Mandates\MandateRenderer` both endpoints share.
The text's actual final wording stays deferred (D-0015(a)) — this session's
renderer produces real, live text (not a placeholder), just not
legally-reviewed copy.

**Response `201`** (slot claimed, deposit PaymentIntent created):
```json
{
  "appointment_id": "…",
  "status": "pending_payment",
  "deposit": { "amount": 5000, "currency": "usd", "client_secret": "pi_…_secret_…" },
  "manage_token": "…",
  "payment_confirmation_token": "…"
}
```
`client_secret` is handed to the frontend to complete payment via Stripe's
Payment Element — the server never sees card data (06's PCI scope
minimization). `payment_confirmation_token` — **added Session 7, D-0021** —
is a distinct, single-purpose signed token the client uses only to call
endpoint 3 below; it is deliberately not the same value as `manage_token`
(least privilege: a leaked manage-booking link must not also grant the
ability to drive payment confirmation/retry).

**Errors:**
- `409 { "error": "SLOT_ALREADY_BOOKED" }` — the exclusion constraint
  rejected the insert (`23P01`, J3/D-0007), **or** Postgres's own
  exclusion-constraint check deadlocked against a genuinely concurrent
  conflicting insert (`40P01` — a real finding from Session 10's
  true-concurrency test, not a hypothetical; both SQLSTATEs map here). The
  frontend's defined response is to re-fetch availability, not to retry the
  same request. **Concurrency-proven, Session 10:** two simultaneous
  requests for the exact same slot — one `201`, one exactly this `409`,
  never a `500` — see `07-testing-strategy.md`.
- `422 { "error": "VALIDATION_FAILED", "fields": {...} }` — bad input,
  including `mandate_accepted` not being `true`.
- `404 { "error": "NOT_FOUND" }` unknown `slug`/`service_id`/`staff_id`.
- `502 { "error": "PAYMENT_PROVIDER_UNAVAILABLE" }` — **added Session 10,
  D-0030.** The appointment row was created and committed (D-0027's
  transaction boundary), then the Stripe PaymentIntent call itself failed;
  the hold is released immediately (appointment moved to `cancelled`,
  `cancelled_reason: "payment_provider_error"`) rather than left to expire
  naturally, so the customer can retry a fresh booking right away.

### 3. `POST /api/bookings/{token}/confirm-payment` — redesigned Session 7, D-0021

Used both for the initial payment attempt (if not resolved synchronously by
step 2's client_secret flow) and for a retry after a decline (J2). **The
path parameter is `payment_confirmation_token` from endpoint 2's response,
not a raw `appointment_id`** — Session 6's audit found this endpoint had no
mechanism to derive tenant context from a bare ID before its RLS-protected
lookup; D-0021 closes that gap by making the token itself the sole
identifier and the carrier of tenant context.

**Request:** no body — the token in the path is the entire input.

**Mechanism (D-0021), in order:**
1. Verify the token's signature, `purpose` (must be `confirm_payment`), and
   `expires_at` before anything else. Any failure here (bad signature,
   wrong purpose — e.g. a `manage_token` presented here — or expired) is
   rejected as `404 { "error": "INVALID_OR_EXPIRED_TOKEN" }`, which reveals
   nothing about whether any appointment exists, since there is no separate
   raw ID in the request to leak against.
2. Only once verified, set the request's tenant context from the token's
   own signed `tenant_id` — never from any other input.
3. Perform the RLS-protected read/write against the token's
   `appointment_id`.

**Not single-use:** valid for repeated calls while the appointment's
`status = pending_payment` (supports retrying with a different card, per
J2). Once the appointment leaves `pending_payment`, further calls with the
same token are idempotent — they return the current state, never a new
mutation or a duplicate confirmation email (same idempotency discipline as
J9's webhook handling).

**Response `200`:** `{ "status": "confirmed" }` on success, or
`{ "status": "pending_payment", "last_payment_error": { "code": "card_declined", "message": "…" } }`
on a synchronous decline — the customer sees this and can retry with a
different card, per J2, using the same token again.

**Errors:**
- `404 { "error": "INVALID_OR_EXPIRED_TOKEN" }` — bad signature, wrong
  `purpose`, or the token's own `expires_at` has passed (D-0021).
- `409 { "error": "BOOKING_EXPIRED" }` — the token is otherwise valid, but
  the hold window already lapsed and a scheduled job already moved the
  appointment to `cancelled` — distinct from `SLOT_ALREADY_BOOKED`, since
  this is "your own hold expired," not "someone else won the race."

### 3b. `POST /api/bookings/manage/{token}/cancel` — built Session 21, D-0052

Customer-initiated self-service cancellation, the counterpart to endpoint 4's
studio-initiated `cancel()` (D-0051) — same bookkeeping-only discipline (no
Stripe call, no `refunds` row), different actor attribution.

**Request:** the same `manage_token` endpoint 2's `201` response already
returns (`GET .../manage/{token}` above uses the identical token) — **not**
a new token purpose, per D-0052. Optional body: `{ "reason": "…" }`.

**Mechanism:** identical token verification to `GET .../manage/{token}`
(signature, `purpose = manage_booking`, expiry — D-0021), then the same
tenant-context establishment from the token's own signed `tenant_id`. Only
`pending_payment`/`confirmed` appointments are cancellable (mirrors
`Owner\AppointmentController::CANCELLABLE_STATUSES`, D-0051); a terminal
status, including an already-`cancelled` one, is rejected rather than
silently treated as idempotent.

**Response `200`:** `{ "appointment_id", "status": "cancelled", "starts_at", "ends_at" }`.

**Errors:**
- `404 { "error": "INVALID_OR_EXPIRED_TOKEN" }` — identical to the lookup
  endpoint's own token-verification failure (bad signature, wrong purpose,
  expired) — no separate raw id in this request to leak a distinct error
  against.
- `409 { "error": "INVALID_STATUS_TRANSITION" }` — the appointment is
  already `completed`/`no_show`/`cancelled`.

### 4. `PATCH /api/owner/appointments/{id}/status` — `completed`/`no_show` built Session 17, D-0042

**Sibling note (D-0055, Session 25):** `GET /api/owner/appointments`'s
response now also carries `no_show_count` — a plain integer, tenant-wide,
counting rows where `status = 'no_show'` within the request's `from`/`to`
window (the same window as the returned list), independent of the `status`
query filter. See D-0055 for why: it is the count that endpoint 4 itself
produces by writing `no_show` via an explicit owner action, never a value
derived from FR-05's hold-window-expiry mechanism.

**Request:** `{ "status": "no_show" }` or `{ "status": "completed" }`.
`{ "status": "cancelled", "reason": "…" }` is documented here as a future
target (J7) but is **not** accepted by the built endpoint — see D-0042 for
why it was deliberately left out this session (a distinct workflow with its
own refund considerations, not yet reasoned through).

**Response `200`:** the updated appointment, as a flattened projection —
`{ "id", "status", "starts_at", "ends_at", "customer_name", "service_name", "staff_name", "deposit_status" }`
— not the raw Eloquent row. `deposit_status` is `null` if no deposit
payment row exists yet. Auto-charge balance handling on `completed` (the
original draft of this row) is **not** built — that's J5's off-session
balance charge, explicitly out of this session's scope; marking `completed`
is bookkeeping only, per D-0006 (no Stripe call, no `payments`/`refunds`
row — see D-0042).

**Errors:** `409 { "error": "INVALID_STATUS_TRANSITION" }` — the only valid
prior status for either target is `confirmed` (04's state machine names
exactly one incoming transition for each), so e.g. attempting to mark
`completed` a booking that's still `pending_payment`, or re-marking an
already-`completed`/`no_show` appointment, both get this. `404
{ "error": "NOT_FOUND" }` — **corrected Session 17 from this row's original
`403`** — an appointment belonging to another tenant is invisible to a
`BelongsToTenant` + RLS-scoped lookup, indistinguishable from a nonexistent
one, matching every other tenant-scoped controller in this codebase (not a
`403`, which would require an out-of-scope cross-tenant existence check).

### 5. `POST /api/owner/appointments/{id}/refund` — built Session 26, D-0056

**Request:** `{ "amount": 5000, "reason": "…" }` (both optional — omit
`amount` for a full refund of the deposit; `reason` is this app's own
free-text `refunds.reason`, never forwarded to Stripe's own reason enum).

**Response `200`:** `{ "refund": { ...the created refunds record... },
"payment_status": "refunded" | "partially_refunded" }`.

**Errors:** `404` if the appointment doesn't exist or belongs to another
tenant (RLS-indistinguishable, same convention as endpoint 4); `409`
(`PAYMENT_NOT_REFUNDABLE`) if the deposit payment isn't `succeeded` — this
covers both a payment that was never captured (no deposit row, or one
still `requires_action`/`failed`) and one that has already been refunded
(fully or partially: `04`'s payment state machine draws both `refunded`
and `partially_refunded` as terminal, so a refund is one-shot per payment,
never incrementally topped up across multiple calls); `422`
(`VALIDATION_FAILED`) if `amount` exceeds the payment's own amount; `502`
(`PAYMENT_PROVIDER_UNAVAILABLE`) if the Stripe call itself throws.

Owner-initiated only (FR-12/J7/J8) — never system-triggered by
cancellation or any other event, matching this codebase's standing
explicit-owner-action pattern for anything touching money. Deliberately
NOT gated on `appointments.status`: J8 allows a refund against an
otherwise still-`confirmed`/`completed` appointment when a dispute
requires it, not only after a cancellation. Records a `booking_events` row
(`event_type: refund_issued`, `actor_type: owner`), the same audit-trail
pattern D-0053 already uses for dispute tracking. Runs behind
`auth.tenant.external` (not `auth.tenant`) — see
`AuthenticateTenantUserWithoutTransactionWrap`'s own docblock — because,
like `BookingController`/`PaymentConfirmationController`, it calls Stripe
mid-request and must not hold a database transaction open across that call
(D-0027).

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
not a silent zero. **Both-configurable (before and after) is now settled,
not provisional** — D-0008's separate ruling on whether MVP restricts
buffer to "after only" was resolved by D-0024 (Session 7): both stay
configurable, confirming this endpoint's shape as originally written rather
than changing it.

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

**Resolved, Session 7 (D-0023):** `04-data-model.md`'s
`notification_deliveries.purpose` CHECK list now includes `rebooking_invite`
as its own distinct value (separate from the automatic `rebooking_prompt`),
closing the gap this endpoint's original definition (Session 6) flagged but
didn't fix. This endpoint's send is recorded with `purpose =
'rebooking_invite'`.

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
  hold-window design doesn't fully address on its own; still true after
  Session 10 — no rate limiting was added to the new login endpoints or
  booking creation either).
- **Resolved, Session 10 (D-0029):** auth token mechanics for owner/staff/
  admin — Sanctum SPA (stateful/cookie) mode, one shared `web` guard,
  role-based authorization at the app layer. Session/refresh/expiry follow
  Laravel's own default session lifetime (`config/session.php`,
  `SESSION_LIFETIME`) — no bespoke expiry policy was decided or needed
  beyond that default.
- Password-reset and account-provisioning flows for owner/staff/admin — not
  named by D-0029's ruling, not built; how a studio's first owner account
  actually gets created is still unspecified.
- The hold-window expiry scheduled job (D-0011's mechanism/value were
  already decided; the job enforcing it doesn't exist — a `pending_payment`
  appointment that's never confirmed and never hits a Stripe failure has
  nothing that cancels it yet).
- Backfilling `payment_mandates.stripe_payment_method_id` once a payment
  method is actually attached (D-0031) — the real webhook handler or a real
  (non-stubbed) confirm-payment implementation would do this; neither
  exists yet.
