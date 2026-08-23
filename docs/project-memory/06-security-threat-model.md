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

## Deferred to a future session

A full threat model (trust boundaries, a STRIDE table, abuse cases, an
authentication/authorization design write-up) belongs to the session that
also produces the real data model and API contract — doing it now, against
a schema that doesn't exist yet, would produce a document that looks
thorough but isn't grounded in anything real.
