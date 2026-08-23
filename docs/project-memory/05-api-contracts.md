# API / Event Contracts
> Purpose: the interface others depend on.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1 — deferred, see note)

No API exists yet. This is deferred to a future session, done once
`02-requirements.md` and `04-data-model.md` are complete — an API contract
written ahead of both would be guessing at shapes rather than deriving them.

## Anticipated direction (not yet implemented)

- A JSON API served by the Laravel backend, consumed by the decoupled Nuxt
  frontend (see `03-architecture.md`, decision D-0002 in
  `09-decision-log.md`) — the decoupling is what makes a real, versioned
  contract necessary here in a way it wasn't for this developer's
  Inertia-based work.
- Inbound Stripe webhooks (payment and Connect account events) are a
  distinct, unauthenticated-but-signature-verified surface — see
  `06-security-threat-model.md`'s webhook signature verification
  requirement.

## Deferred to a future session

- Endpoint/schema summary.
- Authentication and authorization model (customer vs. owner vs. staff
  tokens).
- Error model, versioning/deprecation policy, idempotency/pagination/rate
  limits.
- Events published/consumed (e.g., reminder-dispatch queue jobs).
