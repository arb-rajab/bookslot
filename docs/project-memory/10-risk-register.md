# Risk Register
> Purpose: known risks, owned and reviewed rather than forgotten.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1)

| ID | Risk | Category | Impact | Likelihood | Mitigation | Status | Review date |
|---|---|---|---|---|---|---|---|
| R-01 | No real design-partner pilot exists yet — every business assumption in `00-project-brief.md` is reasoned, not validated | Business/discovery | High — the whole product could be built against wrong assumptions | High (this is the current state) | Recruit one real pilot studio before investing heavily in MVP feature breadth; treat pilot recruitment as the top-priority next step, not a nice-to-have | Open | Next session |
| R-02 | Multi-tenancy is genuinely new territory for this developer (contrast: `privacy-forge` is single-org) — a cross-tenant data leak would be severe | Security/architecture | High — direct trust failure if it happens in production | Medium (new pattern, not yet implemented or tested) | Dedicated, exhaustive tenant-isolation test suite before pilot readiness (see `01-scope-and-non-goals.md`'s Definition of MVP complete) | Open | Before first real pilot goes live |
| R-03 | Stripe Connect Express onboarding friction may be a genuine adoption blocker for non-technical owners, not just minor friction | Product/adoption | Medium — could stall onboarding entirely for some owners | Unknown (unvalidated) | Learn directly from a real pilot's onboarding experience; have a fallback plan (e.g., hands-on onboarding assistance) ready for the first few pilots | Open | After first pilot onboarding attempt |
| R-04 | Automated reminders may not measurably reduce no-show rate as assumed | Product/business model | High — this is the core value proposition | Unknown (unvalidated) | Measure real before/after no-show rate against a real pilot studio's own prior rate; be willing to revise the value proposition if the effect is small | Open | After pilot has enough appointment volume to measure |
| R-05 | Email/SMS deliverability (reminders landing in spam or not being delivered) undermines the core value proposition silently | Operational | Medium-High — a reminder that doesn't arrive is worse than no reminder feature at all, since the owner believes it's working | Medium | Deliverability tuning work in a future deployment session; monitor delivery/open rates, not just send success | Open | During implementation session that builds reminders |
| R-06 | Price-sensitive target buyer may not tolerate even a modest recurring fee plus transaction percentage | Business model | High — determines whether the business is viable at all | Unknown (unvalidated) | Validate willingness-to-pay directly and early with a real pilot, before building deep into paid-expansion features | Open | After first pilot pricing conversation |

## Closed risks

None yet — this is the first session.
