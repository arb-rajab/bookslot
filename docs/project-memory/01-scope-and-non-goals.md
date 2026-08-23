# Scope and Non-Goals
> Purpose: prevent scope creep by writing down what this will never do.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1)

## MVP boundary (in scope)

None of the following is built yet — this is the target boundary for a
first sellable version, to be implemented in future sessions.

- [ ] Public booking page per business: service list (name, duration,
      price, deposit amount or %), available slot picker for a given
      staff/resource.
- [ ] Deposit collection at booking time via Stripe Connect (fixed amount
      or percentage of service price, configured per service by the
      owner).
- [ ] Automated reminders (email + SMS) at owner-configurable intervals
      (illustrative default: ~7 days, ~24 hours, ~2 hours before the
      appointment).
- [ ] Balance handling at/after the appointment: either an automatic charge
      of the remaining balance via the customer's saved payment method, or
      a manual "mark as paid in person" action for the owner.
- [ ] No-show flagging: owner marks an appointment as a no-show; the
      deposit's forfeiture/refund behavior is explicit and owner-configured
      per studio policy, not silently decided by the product.
- [ ] Single business, single location, one or more staff/resources per
      business (each with their own calendar) — see non-goals for what
      "one or more staff" deliberately does not include.
- [ ] Owner dashboard: calendar view of upcoming appointments, deposit/
      balance payment status per appointment, basic no-show count.
- [ ] Post-appointment rebooking prompt: a simple automated message with a
      link back to the booking page, sent once after a completed
      appointment.
- [ ] Stripe Connect (Express) onboarding for the business to receive
      payouts.

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
