# Testing Strategy
> Purpose: what we test, at which level, and why that is sufficient.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1 — deferred, see note)

No code exists yet, so no testing strategy can be meaningfully written
against real components. Deferred to the session that produces the first
real schema/API. One anticipated requirement is recorded now so it isn't
lost:

## Anticipated requirement (not yet implemented)

- Per-tenant data isolation needs a dedicated, exhaustive test suite before
  this product could be considered pilot-ready — analogous in spirit to
  the authorization-matrix testing this developer used in `privacy-forge`,
  but proving tenant boundaries rather than role boundaries. See
  `01-scope-and-non-goals.md`'s Definition of "MVP complete" and
  `06-security-threat-model.md`.

## Deferred to a future session

- Testing philosophy, levels (unit/feature/browser), tools.
- Security testing (e.g., webhook signature bypass attempts, cross-tenant
  access attempts).
- Test data strategy (synthetic only — no real customer data should ever
  appear in a test fixture, matching this developer's practice elsewhere).
- Quality gates in CI.
