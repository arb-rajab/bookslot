# Security and Threat Model
> Purpose: what can go wrong, and what stops it.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1 — design-level only, no code exists yet)

This is a design-level pass appropriate to a business-framing session, not
a full STRIDE exercise against real code (there is none yet). A future
architecture/implementation session should expand this into a fuller
threat model once the schema and API contract exist.

## What this product touches, and why that shapes the design

Unlike `privacy-forge` (a compliance tool whose entire subject matter is
personal data), this is a commercial booking product whose personal-data
handling is a *means*, not the product itself — but it still handles real
personal data on behalf of a business tenant (its customers' names,
contact details, appointment history) and real payment flows, so the same
seriousness applies even though this isn't a privacy-tooling product.

## Design-level controls

- **PCI scope minimization.** The application must never receive, store,
  or transmit raw card data. Deposit and balance payments are collected via
  Stripe's hosted Payment Element / Checkout — the server only ever sees a
  Stripe token/PaymentIntent ID, never a PAN. This targets PCI DSS SAQ A
  eligibility (the lowest-burden self-assessment tier), which is a
  deliberate design constraint, not an afterthought — building anything
  that touches raw card data would be a materially larger compliance
  undertaking this product has no business reason to take on.
- **Webhook signature verification.** Every inbound Stripe webhook
  (payment success/failure, Connect account status changes, disputes) must
  verify the `Stripe-Signature` header against the endpoint's signing
  secret before processing. Combined with idempotency handling (deduping on
  Stripe's event ID) to guard against replay and duplicate-delivery
  double-processing — a compromised or replayed webhook must not be able
  to mark a deposit as paid, or a payout as sent, that never happened.
- **Per-tenant data isolation.** With many independent studios' customer
  and payment data in one shared database (see `03-architecture.md`), every
  tenant-scoped query must be provably scoped to the requesting business.
  This needs to be an explicitly, exhaustively tested property before pilot
  — a cross-tenant data leak (studio A seeing studio B's customer list or
  appointment data) would be a severe trust failure for a product whose
  entire pitch depends on being trustworthy with a business's customer
  relationships.
- **GDPR-erasure-equivalent handling for EU/UK customers.** Even though
  this product is not marketed as a compliance tool, if a studio's own
  customer is an EU/UK data subject, that person's underlying data-
  protection rights (access, erasure) still apply to the personal data this
  product stores about them, regardless of what the product calls itself.
  Design implication: a customer record needs a real deletion path (not
  just a soft "hide from UI" flag) and an export path, even at MVP, for
  whichever tenants operate in or serve customers in the EU/UK. This does
  not need privacy-forge's full DSAR-orchestration machinery — a
  proportionate, simpler owner-facing "delete this customer's data" action
  is likely sufficient at MVP scale, to be sized properly in a future
  requirements session.
- **Secrets and payment credentials.** Stripe secret keys, webhook signing
  secrets, and any per-tenant Connect account identifiers are
  configuration/secrets-managed values, never committed to this repository
  — this applies from the first line of code, not retrofitted later.

## What must remain private forever (design-boundary reminder)

This list exists here as well as in `00-project-brief.md` because it
directly bounds what this security section — or any future architecture
document — should ever contain in concrete form:

- Real pricing and margin structure.
- Real customer data (any real studio's or their customers' actual
  records).
- Real Stripe Connect account structure and identifiers.
- Fraud/risk rules (e.g., chargeback or abuse heuristics) — publishing the
  actual rules would let a bad actor design around them.
- Deliverability tuning detail (provider-specific configuration used to
  keep reminder emails/SMS out of spam folders) — this is operational
  know-how, not something that needs to be public even in an internal doc
  that could later be excerpted elsewhere.

## Amendment (Session 2, 2026-08-24) — two clarifications from resolving 09's D1/D2

- **GDPR-erasure scope is per end-customer row, not per tenant.** The erasure
  requirement above is about a studio's *own customer* being a data subject —
  it applies identically regardless of which tenant-isolation mechanism was
  chosen in D-0005 (`09-decision-log.md`). Row-level tenancy doesn't weaken
  this, and schema-per-tenant wouldn't have strengthened it either; it was
  never a tenancy-boundary question. See `04-data-model.md` for the concrete
  handling (anonymize-in-place, not row deletion, to preserve the studio's own
  accounting/audit records).
- **Chargeback/dispute liability must be disclosed to studio owners, not
  silently absorbed.** D-0006 confirms Stripe Connect destination charges
  assign dispute liability to the connected account (the studio) by default —
  correct, since the studio is merchant of record, but this needs to be
  explicit in onboarding copy (a future session), not discovered by an owner
  at their first real dispute.

## Amendment (Session 3, 2026-08-24) — three clarifications from resolving G1/G2/G3

- **Tenant-context lifecycle closes a real leak vector, not just a
  theoretical one.** Review found that D-0005's RLS backstop, as originally
  specified, didn't define when the session GUC is set/cleared — on a pooled
  or long-lived connection (Horizon workers, PgBouncer), an ambient
  session-level `SET` would leak one tenant's context to the next
  request/job on the same connection. Resolved via transaction-scoped
  `set_config(..., true)`, detailed in `09-decision-log.md` D-0009 and
  summarized in `03-architecture.md`. This directly strengthens this file's
  "per-tenant data isolation" control — the control now has a fully
  specified lifecycle, not just a mechanism.
- **The platform-admin path is now the single narrowest bypass surface in
  the system, not a second `BYPASSRLS` role.** D-0009 revises D-0005's
  `BYPASSRLS`-for-admin phrasing: the live admin endpoint impersonates a
  specific tenant under `bookslot_app` plus an app-layer authorization
  check, rather than running as a role that bypasses RLS outright. This
  matters for this file's threat model specifically because it changes the
  blast radius of an admin-authorization bug from "every tenant's data" to
  "one wrong tenant's data." `BYPASSRLS` itself is now reserved exclusively
  for `bookslot_migrator`, an offline/CI-triggered role never reachable from
  a live request — see D-0009 for the full reasoning.
- **Off-session balance-charge consent is now an explicit, evidenced
  control, not an assumed one.** D-0006's later balance charge (J5) is an
  off-session confirmation against a saved card; without recorded evidence
  of what the customer agreed to (amount, timing, trigger), off-session SCA
  declines are more likely and a resulting dispute is hard to defend. D-0010
  adds a `payment_mandates` table (full rendered-text snapshot, not just a
  template reference, plus IP/user-agent/timestamp/PaymentIntent/
  payment-method IDs) linked into the existing `booking_events` audit trail.
  This is customer-facing consent evidence, distinct from — but related to —
  this file's already-flagged owner-facing disclosure item below: the
  dispute-liability copy is what a *studio* is told about its own exposure
  as merchant of record; the mandate is what a *customer* is told about a
  future charge. Both remain deferred to a copywriting/implementation
  session; neither is drafted here. The exact mandate wording, and whether a
  given card scheme/region requires a formal Stripe SCA mandate flow beyond
  a strong disclosure, is real implementation-session research against
  Stripe's actual rules — not answered in this session.

## Deferred to a future session

A full threat model (trust boundaries, a STRIDE table, abuse cases, an
authentication/authorization design write-up) belongs to the session that
also produces the real data model and API contract — doing it now, against
a schema that doesn't exist yet, would produce a document that looks
thorough but isn't grounded in anything real.
