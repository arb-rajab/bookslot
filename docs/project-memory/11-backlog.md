# Backlog
> Purpose: everything deliberately not being done right now.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1)

## Next up (candidate for the next 1-2 sessions)

| ID | Item | Type | Size | Why now |
|---|---|---|---|---|
| B-01 | Recruit a real design-partner pilot studio | Discovery | Unknown (outside pure engineering effort) | R-01 in `10-risk-register.md` — every assumption in this repo is unvalidated until this happens; it should happen before deep MVP feature work, not after |
| B-02 | Full requirements pass (roles matrix, numbered FR/NFR, data classification) | Requirements | Medium | Deferred from this session (`02-requirements.md`) — needed before real data-model/API work starts |
| B-03 | Full data model and ERD, including the tstzrange exclusion-constraint schema | Architecture | Medium | Deferred from this session (`04-data-model.md`); the anticipated direction is already recorded, this makes it real |
| B-04 | API contract (endpoints, auth model) | Architecture | Medium | Deferred from this session (`05-api-contracts.md`) |

## Later (paid-expansion features — see `01-scope-and-non-goals.md`)

- Multi-location support.
- Waitlist with automatic slot-fill on cancellation.
- Marketing/CRM features beyond the single rebooking prompt.
- Analytics (no-show trends, revenue forecasting, busy-slot analysis).
- Custom branding / custom domain for the booking page.
- Package/membership pricing (prepaid session bundles).
- Staff commission tracking.
- Public API/webhooks for third-party integrations.
- Explicit support for adjacent verticals (hair/beauty salons, driving
  instructors) as marketed, onboarded segments.

## Explicitly rejected (with reasons)

- Full POS/payment-terminal replacement — see `01-scope-and-non-goals.md`'s
  non-goals table.
- General-purpose (non-appointment) calendar/scheduling — same.
- Marketplace/consumer discovery — same; a fundamentally different business
  model, not an incremental feature.
- Native mobile apps at MVP — same; deferred, not rejected outright, but
  explicitly not MVP scope.
