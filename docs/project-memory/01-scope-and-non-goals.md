# Scope and Non-Goals
> Purpose: prevent scope creep by writing down what this will never do.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-09-13 (Session 19 — three items promoted from "Not
> built" to "Built and proven" (Stripe webhook handling, hold-window
> expiry, automated reminders) per D-0046 through D-0050
> (`09-decision-log.md`), under an explicit, bounded reopening of D-0045's
> checkpoint — see that entry for why building resumed and exactly how
> narrow this session's scope was kept. This list's Session 18 framing
> below is otherwise left as written: it is still a closed accounting of a
> checkpoint, amended in place for what actually changed, not silently
> re-opened into a forward-looking target list again.)

## MVP boundary — final accounting (Session 18 checkpoint, amended Session 19)

Sessions 2 through 17 built against the target boundary below. Per
**D-0045** (`09-decision-log.md`), this checklist is no longer a forward
target implicitly inviting the next session to keep checking boxes — it is
a closed, honest accounting of exactly where each item actually stands as
of this checkpoint. Three states, no fourth: **built and proven**, **not
built** (stated plainly — never "almost done" when it was never started),
or **permanently unverifiable by deliberate project-scope choice** (not a
gap anyone intends to close within this project's lifecycle). Session 19
(D-0046) explicitly reopened this checkpoint for a bounded, named scope —
see that entry — and amended the three items below accordingly; every
other item's status is exactly as Session 18 left it.

### Built and proven

Each of these is real, working application code, exercised by an automated
test that fails if it regresses — not merely coded and assumed correct.

- [x] **Public booking page per business** — service/slot selection against
      a real derived-availability algorithm (working hours, exceptions,
      buffer-aware occupancy; D-0039), a real Nuxt page calling the real
      backend over real HTTP with the real CORS/CSRF/cookie protocol
      (D-0041, Session 16). *Caveat: see "Permanently unverifiable" below —
      the page's client-side JavaScript has never executed in an actual
      browser (R-08).*
- [x] **Deposit collection at booking time** — an immediate-capture Stripe
      PaymentIntent (destination charge, `application_fee_amount`) that
      also saves the payment method for later off-session use (D-0006), a
      real, tested multi-transaction boundary around the Stripe call
      (D-0027, D-0030), and a real two-process concurrency test proving the
      `23P01`/`40P01` → `409 SLOT_ALREADY_BOOKED` guarantee under genuine
      OS-level concurrency (D-0030). *Caveat: proven against
      `FakePaymentIntentGateway` only — see "Permanently unverifiable."*
- [x] **Payment confirmation** — `POST /api/bookings/{token}/confirm-payment`
      built end to end (D-0021, D-0033), including the payment-method
      write-back to `payment_mandates` that R-07 exists to guard.
- [x] **Tenant isolation** — row-level `tenant_id` with a Postgres
      Row-Level-Security backstop, fail-closed by construction (D-0005,
      D-0009), a dedicated tenant-isolation test suite (`composer
      test:tenant-isolation`, 20/20 as of Session 17/18, well under D-0017's
      60-second budget) — the one correctness property `01`'s original
      Definition of MVP complete named as non-negotiable, and the one item
      on this list this project cannot honestly ship without. Built and
      exhaustively tested, not merely coded.
- [x] **Owner attendance / no-show marking** — `GET /api/owner/appointments`
      and `PATCH /api/owner/appointments/{id}/status` (D-0042, Session 17),
      real writes to the `booking_events` audit trail, a real owner-facing
      Nuxt dashboard page calling the real backend (Session 17). *Caveat:
      same browser-execution caveat as the booking page (R-08); the
      `cancelled` transition and any refund interaction were deliberately
      left out (D-0042), not built.*
- [x] **No-show count on the owner dashboard** (FR-15, the piece D-0042
      left unbuilt) — `GET /api/owner/appointments` now returns a
      tenant-wide `no_show_count` alongside the appointment list, rendered
      on the same real owner-facing dashboard page (D-0055, Session 25). A
      raw count only, reading the explicit-owner-action `no_show` status
      (J4), not FR-05's unrelated hold-window-expiry mechanism.
- [x] **Owner-initiated refund** (FR-12, `05`'s endpoint 5, the piece
      D-0042/D-0051 both explicitly left unbuilt) — `POST /api/owner/
      appointments/{id}/refund` (D-0056, Session 26): full or partial
      refund of an already-captured deposit, in test-mode Stripe only
      (D-0036), independent of the appointment's own status (J8's dispute
      path, not only J7's post-cancellation path), a one-shot action per
      payment per `04`'s state machine, a `booking_events` audit row
      (`refund_issued`). **Owner-admin UI built Session 36 (D-0062):** a
      "Refund deposit" section on the owner appointment-detail page,
      shown only while the deposit is actually refundable, gated behind an
      explicit confirm step before the request fires — see D-0062 for the
      full placement/confirmation reasoning shared across all five UI
      triggers this session added.
- [x] **Owner-initiated customer re-invite** (FR-23/D-0014, `05`'s endpoint
      9 — already specified since Sessions 5-7, just never built) —
      `POST /api/owner/customers/{id}/re-invite` (D-0060, Session 30): a
      manual, owner-triggered resend of the public-booking-page link to a
      specific customer, independent of whether their last appointment was
      a no-show, reusing `SendAppointmentReminderJob`/
      `AppointmentReminderMail` directly rather than parallel
      infrastructure. Deliberately repeatable — each call is a real, new
      send, never deduplicated against a prior one (the opposite of
      refund/status-update's one-shot shape) — which required narrowing
      D-0050's reminder fire-once unique index to a partial index excluding
      this one purpose. **Owner-admin UI built Session 36 (D-0062):** a
      "Send re-invite" button on the new owner customer-detail page, with a
      read-only "last invited" line derived from already-loaded
      `booking_events` — never a cooldown or dedup of any kind, matching
      this endpoint's own by-design repeatability.
- [x] **Owner-initiated off-session balance charge** (J5, `05`'s endpoint 6,
      the item named unbuilt below through Session 26) — `POST /api/owner/
      appointments/{id}/balance/charge` (D-0057, Session 27): a real
      off-session Stripe charge (test-mode only, D-0036) against the card
      saved at deposit time (`payment_mandates.stripe_payment_method_id`,
      no Stripe Customer object involved, same as the deposit side),
      gated on `appointments.status === 'completed'` plus a captured
      deposit, charging the mandate's own disclosed balance figure rather
      than a live recomputation, a `booking_events` row on every attempt
      (success or failure), and explicit handling of the two off-session-
      specific failure modes (card declined, SCA authentication required)
      as expected `200` outcomes per `05`'s contract, not exceptions.
      Owner-initiated only, via this dedicated route — the "studio policy
      is auto-charge" trigger J5's prose describes was never built (no
      such policy column exists on `tenants`/`services`), matching D-0056's
      own reasoning for why refund is explicit-owner-action-only too.
      **Owner-admin UI built Session 36 (D-0062):** a plain "Charge
      remaining balance" button on the owner appointment-detail page — no
      amount input, no auto-charge-policy toggle of any kind, per D-0061 —
      behind the same confirm step as refund, with the endpoint's two real
      `200` outcomes (succeeded / declined-and-retriable) rendered
      distinctly from a genuine `502` provider failure.
- [x] **The reconciliation safeguard for R-07** — `mandates:reconcile-backfill`
      (D-0037), scheduled hourly, flags any `payment_mandates` row whose
      `stripe_payment_method_id` sits `NULL` past a reasoned 30-minute grace
      period, tested for the flagged/not-flagged/already-backfilled/
      cross-tenant cases. This is the half of R-07 that never depended on
      real Stripe access — built and tested. *A real, named, separate gap:
      no cron/scheduler process actually runs anywhere yet
      (`08-deployment-and-operations.md`); the command is eligible to run,
      not yet wired into a live scheduler.*
- [x] **Stripe webhook handling (J9)** — Session 19, D-0048:
      `POST /api/webhooks/stripe`, real signature verification, dedup by
      Stripe event id, `payment_intent.succeeded`/`payment_intent.payment_failed`
      handled idempotently (late-arrival and duplicate-delivery cases both
      tested), via a real message-queue job (`ProcessStripeWebhookJob`) on
      the new RabbitMQ broker (D-0047). *Session 22 (D-0053): the
      `charge.dispute.created`/`charge.dispute.closed` tenant-resolution gap
      this caveat used to describe is closed — `ResolveStripeDisputeTenantJob`
      resolves the tenant from the Dispute's own `payment_intent` field
      (the same `payments.stripe_payment_intent_id` lookup every other
      handler here already uses), and `ProcessStripeWebhookJob` now records
      a `booking_events` audit entry for it, per `05`'s own pre-existing
      "tracked, not auto-resolved" target. D-0036's real-Stripe-network
      limitation is unchanged — every test here, dispute cases included,
      signs its own fixture payload locally, never a live Stripe delivery.*
- [x] **Hold-window expiry enforcement (FR-05)** — Session 19, D-0049:
      `ReleaseExpiredPendingBookingJob`, dispatched with a real delay (the
      new RabbitMQ broker's TTL+dead-letter-exchange mechanism, D-0047) at
      booking-creation time, releases a still-`pending_payment` slot back
      to availability; re-checks live status before acting, so a booking
      confirmed or cancelled by any other path in the meantime is never
      undone. D-0011's mechanism, finally wired to a real job.
- [x] **Automated reminders (D-0050)** — Session 19: `reminders:dispatch`
      (scheduled every 15 minutes) plus `SendAppointmentReminderJob` turn
      `notification_deliveries`' pre-existing `reminder_7d`/`reminder_24h`/
      `reminder_2h` schema into real, tested, exactly-once sends, guarded by
      a real unique constraint (not just an assumed-safe check-then-create).
      *Caveat, the same accepted-limitation shape as D-0036's for Stripe:
      `MAIL_MAILER=log` — no real email/SMS provider has ever been obtained
      for this project, so every reminder is written to a log line today,
      not a real inbox. R-04 (cadence effectiveness) and R-05
      (deliverability) both remain exactly as open as before — this item
      answers neither; it only makes them answerable by a future real
      pilot.*

### Not built — stated plainly, never started

No partial credit is claimed for any of these. None has so much as a
migration, a route, or a stub controller behind it.

- [ ] **Studio-configured "auto-charge" balance policy** — J5's prose
      ("if the studio's policy is auto-charge") describes a per-studio
      toggle that would trigger `05` endpoint 6 automatically on
      `completed`, without an owner click. No such policy column exists on
      `tenants`/`services`, and Session 27 (D-0057) deliberately did not
      invent one — the endpoint it built is explicit-owner-action-only,
      same standing pattern as refund (D-0056). Distinct from "the
      off-session charge itself was never written," which Session 27
      closed — see the built-and-proven entry above. **Session 35 (D-0061)
      scoped this in full and deliberately deferred it** — not merely
      re-confirmed as undesigned. Concretely: a per-tenant, two-valued
      setting, read at appointment-`completed` time to gate an automatic
      background charge; deferred (no schema, no job, not even an inert
      settable column) because a real implementation needs the charge
      trigger to key off a value *snapshotted at mandate-acceptance time*
      (never a live tenant lookup, to avoid retroactively surprising a
      customer whose consent predates a later policy change), needs to
      handle a Connect-`restricted` tenant without a silent background
      failure (D-0058), and because R-01 (no real pilot has ever run this
      product) gives no validated signal that owner-click friction on
      endpoint 6 is worth pre-building speculative infrastructure for.
      D-0057's owner-initiated-only design stands as this project's only
      balance-charge trigger until a future session has real product
      justification to build the full mechanism. Session 35 also found
      and fixed an independent, live accuracy bug this investigation
      surfaced: `MandateRenderer`'s consent text had unconditionally
      promised an "automatically charged" balance since Session 10, even
      though no automatic mechanism has ever existed — corrected to
      describe the owner-timed reality (charge or manual collection, "at
      [tenant]'s discretion") without inventing new disclosure content.
- [ ] **Post-appointment rebooking prompt** — zero code. Not started.
- [x] **Stripe Connect (Express) onboarding** for a business to receive
      payouts — **built Session 28, D-0058**: account creation, hosted
      Account Link generation/refresh, and a live status-check endpoint
      (`05-api-contracts.md` endpoint 10), plus `account.updated`/
      `account.application.deauthorized` webhook handling keeping
      `tenants.stripe_onboarding_status` current. **Owner-admin UI built
      Session 36 (D-0062):** `/owner/settings/stripe`, the exact frontend
      route `services.stripe.connect_onboarding_redirect_url` was already
      configured to point at since Session 28 — a live status display (all
      four classified states) plus a "start/resume/refresh onboarding"
      action that redirects the browser straight to Stripe's own hosted
      page (real navigation, never rendered as an in-app result, since a
      redirect back doesn't itself prove onboarding finished — the page
      re-checks live status on return rather than trusting the query
      string). No account-replacement flow after a deauthorized Connect
      account exists either — a separately-scoped, undesigned feature,
      unchanged by this session.

### Permanently unverifiable by deliberate project-scope choice

These are not gaps awaiting a future session — they are things this
project has explicitly decided never to obtain within its own lifecycle,
recorded so a reader doesn't mistake "untested" for "someone forgot."

- **Real Stripe network behavior** (D-0036) — real API response shapes,
  real decline codes, real webhook payloads, real latency variance have
  never been exercised and, per D-0036, never will be: the project owner
  explicitly, permanently descoped ever obtaining real Stripe test-mode
  credentials for this portfolio/skill-proof project. Every payment code
  path is real, correct code (`StripePaymentIntentGateway` exists and is
  unit-testable) that has simply never spoken to Stripe's actual
  infrastructure, by deliberate choice, not oversight.
- **Real browser execution of the frontend** (R-08) — no click, keystroke,
  or rendered pixel on either the public booking page or the owner
  dashboard has ever been observed by a real browser or a browser-
  automation tool; both pages have been proven only at the HTTP-protocol
  level (real backend requests replicated by script) and via SSR (Node-side
  Vue rendering with no hydration or event handlers engaged). This item
  differs from the Stripe one above in mechanism — `10-risk-register.md`
  still carries R-08 formally "Open," not "will not do," since no one has
  decided a real browser pass should never happen — but it belongs in this
  bucket for this checkpoint's purposes anyway: three consecutive sessions
  that each added a frontend surface checked for browser-automation tooling
  and found none available, and this project has no standing plan to
  acquire one. Practically, within this project's actual working
  conditions to date, it has behaved exactly like a permanently
  unverifiable item, not a scheduled one — recorded here rather than left
  to imply it's simply next in line. Should tooling become available, or
  before either page is shown to a real person, R-08's own entry in
  `10-risk-register.md` is the authoritative place a future session should
  act on it.

## Explicit non-goals

| Non-goal | Why excluded | Would reconsider if |
|---|---|---|
| Full point-of-sale / in-person payment terminal | Different problem (hardware, full-transaction retail checkout) that would put this product in direct competition with Square/Clover on their own turf; deposits-and-balance-via-link is a narrower, achievable slice | A pilot studio specifically asks for full POS replacement and the deposit-only product is otherwise validated |
| General-purpose calendar/scheduling (meeting rooms, non-appointment use cases) | Different buyer and different feature shape (recurring internal meetings vs. customer-facing paid appointments); would dilute the deposit/no-show focus that is this product's actual value proposition | Never planned as a pivot — a generic scheduler is a different product with different buyers |
| Marketplace / consumer discovery (helping a customer find a *new* studio) | This is a tool for an existing business's existing and prospective customers who already know the business, not a Yelp/Booksy-style two-sided discovery marketplace — that is a fundamentally different business model (supply acquisition, consumer trust, take-rate dynamics) | Only as a deliberate, separately-reasoned future pivot — not an incremental MVP feature |
| Native mobile apps | Mobile web (via the frontend) is sufficient for both the customer booking flow and the owner dashboard at MVP; native apps add app-store review, distribution, and maintenance overhead disproportionate to a product with no validated demand yet | After a real pilot validates the product and owner mobile usage patterns specifically show a mobile-web gap a native app would fix |
| Payroll, accounting, or tax software | Regulated adjacent domain with its own compliance burden and liability profile that doesn't map to this product's core value proposition (booking + deposits + reminders) | If a specific paying customer segment demands it and it can be scoped as a narrow, clearly-bounded integration rather than owned functionality |
| Multi-location / franchise support | Needs role hierarchies, cross-location reporting, and likely a different pricing model — solving it now for a segment (chains) not in the MVP's target buyer would slow down validating the single-location case first | Once single-location product-market fit is validated and a multi-location prospect is actually asking |
| Multi-currency / multi-country tax handling | Stripe Connect's single-country flows are materially simpler to build and reason about; international tax (VAT/GST handling per jurisdiction) is a real compliance surface not worth taking on before any customer outside the initial target market exists | The first pilot or paying customer is outside the initial target country |
| Multi-resource appointments (e.g., an appointment needing two staff or a staff member plus equipment simultaneously) | Adds real scheduling-conflict complexity (multi-resource overlap checking) the tattoo-studio MVP vertical doesn't need — one artist per appointment is the common case | A different vertical (e.g., a service needing an assistant) becomes a real target and single-resource scheduling is otherwise proven |

## Paid-expansion features (post-MVP, monetizable tiers — not built at MVP)

These are plausible, deliberately deferred — not committed roadmap:

- Multi-location support (see non-goals above for why it's deferred, not
  rejected).
- Waitlist with automatic slot-fill when a booking is cancelled.
- Marketing/CRM features beyond the single rebooking prompt: campaigns,
  loyalty or repeat-visit incentives, customer segmentation.
- Analytics: no-show rate trends over time, revenue forecasting, busiest
  time-slot analysis.
- Custom branding / a custom domain for the booking page (vs. a
  bookslot-hosted subdomain at MVP).
- Package or membership pricing (prepaid bundles of sessions).
- Staff commission tracking.
- Public API / webhooks for third-party integrations (accounting software,
  other POS systems).
- Adjacent verticals beyond tattoo studios (hair/beauty salons, driving
  instructors) as explicitly supported, marketed segments — the MVP is not
  architecturally locked to tattoo studios specifically, but marketing and
  onboarding copy targeting a second vertical is deliberately post-MVP.

See `11-backlog.md` for how these are currently prioritized (none are
committed yet — this list is a menu, not a roadmap).

## Definition of "MVP complete" (for a future pilot-readiness session)

The MVP may be considered pilot-ready only when:
1. Every box in the MVP boundary checklist above is checked and
   demonstrably working end-to-end against a real Stripe test-mode Connect
   account, not merely coded.
2. At least one real business (even a friendly/design-partner pilot, not
   necessarily a paying customer yet) has completed the full critical
   workflow — booking, deposit, reminder, attendance, balance, rebooking
   prompt — with a real (test-mode or small real) transaction.
3. Per-tenant data isolation has a dedicated, exhaustive test suite
   (analogous in spirit to the ABAC authorization matrix testing this
   developer used in `privacy-forge`, but here proving tenant boundaries
   rather than role boundaries) — this is the one correctness property an
   MVP genuinely cannot ship without, given the business model depends on
   many independent studios' customer and payment data living in the same
   system.
4. No item in the non-goals table above has silently crept back into
   scope.

**Session 18 checkpoint note (see D-0045, `09-decision-log.md`):** condition
1 above, as originally written, is no longer reachable within this
project's own current scope — D-0036 permanently descoped real Stripe
test-mode credentials, so "demonstrably working end-to-end against a real
Stripe test-mode Connect account" cannot happen without a future, separate
scope change reopening that decision. This definition is left unedited
above as the honest historical record of what Session 0/1 originally meant
by "MVP complete" — not rewritten to quietly redefine "complete" downward
to match what was actually built. The MVP boundary checklist earlier in
this file is the accurate, current accounting of what exists; this
Definition of MVP complete is the accurate, current record of a bar that
was never cleared and, on its own original terms, no longer can be.
