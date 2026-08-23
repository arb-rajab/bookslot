# Decision Log
> Purpose: why things are the way they are, so decisions are not silently undone.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1)

This is a private repository — full ADR ceremony (separate `docs/adr/`
files per decision, as used in the public flagships) is not required here.
Lightweight inline entries are enough, per this developer's own judgment
call for private-track work.

## D-0001 — Reuse Laravel + PostgreSQL, deliberately, despite this being a repeated stack

- **Date:** 2026-08-23 · **Status:** accepted
- **Context:** the public portfolio track has a governance rule pushing
  against repeating a primary backend/database across public repositories,
  to demonstrate technical range to a reviewer. This repository is private
  commercial work with no such reviewer audience, and that rule explicitly
  does not apply to private repositories.
- **Decision:** use Laravel (PHP) as the backend and PostgreSQL as the
  database, matching this developer's other proven work.
- **Why (business fitness, not laziness):** this targets a price-sensitive
  market (see `00-project-brief.md`) where delivery speed and low ongoing
  operating cost decide commercial viability. Spending build time learning
  an unfamiliar stack for this specific product would misallocate scarce
  effort away from the parts of this product that are genuinely novel and
  risky (Stripe Connect, multi-tenancy) and toward re-deriving skills
  already held. PostgreSQL is additionally justified on its own technical
  merits independent of reuse — its exclusion-constraint mechanism is a
  strong fit for the double-booking-prevention problem (see
  `03-architecture.md`).
- **Must not be silently reversed because:** if a future session proposes
  switching stacks "for variety" or "to try something new," that is
  optimizing for the wrong incentive — this is not a public portfolio piece
  being judged on range. A real reason (e.g., a genuine technical
  limitation discovered during implementation) would be needed to revisit
  this.

## D-0002 — Nuxt frontend, decoupled from the Laravel API (not Inertia)

- **Date:** 2026-08-23 · **Status:** accepted
- **Context:** this developer's other Laravel work (`privacy-forge`) uses
  Vue via Inertia — a tightly coupled, server-driven frontend pattern well
  suited to an authenticated internal-tool-style UI.
- **Decision:** use a decoupled Nuxt (Vue) frontend calling a Laravel JSON
  API, not Inertia.
- **Why:** the public-facing booking page is this product's most SEO- and
  performance-sensitive surface (reached via social/business-profile links,
  on mobile data, by a customer likely to bounce if it's slow) — a use case
  Inertia is not designed around the way Nuxt's SSR is. A decoupled API
  also avoids locking a future mobile app (a plausible paid-expansion item)
  into an Inertia-shaped backend.
- **Trade-offs accepted:** more upfront integration work (API auth, CORS,
  a real versioned contract) than Inertia's same-process convenience.
  Accepted because the SSR/SEO need is concrete for this specific product.
- **Must not be silently reversed because:** switching back to Inertia
  later would mean re-doing the public booking page's rendering model —
  worth deciding once, deliberately, now.

## D-0003 — Stripe Connect (Express), not plain Stripe payments

- **Date:** 2026-08-23 · **Status:** accepted
- **Context:** this product is a platform facilitating payment from a
  business's customer to that business, taking a platform fee — not a
  merchant selling its own goods.
- **Decision:** Stripe Connect, Express account type, for connected
  (tenant) businesses.
- **Why:** Connect is Stripe's product for exactly this platform-fee model;
  plain Stripe assumes the platform is the merchant of record. Express
  (over Standard/Custom) trades some white-labeling for Stripe-hosted KYC/
  onboarding UI, minimizing integration burden for a self-serve,
  non-technical small-business owner — the right trade-off at MVP.
- **Must not be silently reversed because:** switching account types later
  (e.g., to Custom for more branding control) means re-doing onboarding and
  potentially re-onboarding existing connected accounts — a real migration,
  not a config change.

## D-0004 — No framework-allocation-ledger check for this repository

- **Date:** 2026-08-23 · **Status:** accepted (procedural, not technical)
- **Context:** the prompt that opened this repository's work explicitly
  states the public-track ledger rule doesn't apply to private
  repositories.
- **Decision:** this repository does not carry a `00a-ledger-confirmation.md`
  file, and no ledger-collision check gates its progress.
- **Why:** recorded here so a future session doesn't mistakenly reintroduce
  public-track ceremony that doesn't fit private commercial work.
