# Architecture
> Purpose: how the system is structured and why.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1 — direction only, not yet implemented)

This is a **recommended direction**, reasoned through at business-framing
depth. It has not been implemented, and no code exists yet. A future
architecture session should treat this as a strong starting point to
confirm or revise against real implementation constraints, not as a frozen
decision (see `09-decision-log.md` for the one thing that *is* meant to be
a real, load-bearing decision this session: the stack choice itself and the
reasoning for reusing it).

## Recommended stack

| Layer | Choice | Status |
|---|---|---|
| Backend API | Laravel (PHP) | Reused, proven in this developer's other work |
| Frontend | Nuxt (Vue) | New for this product — see justification below |
| Database | PostgreSQL | Reused, proven |
| Cache / queues | Redis | Reused, proven |
| Payments | Stripe Connect (Express accounts) | New for this product — see justification below |

## Why Laravel + PostgreSQL, reused (not portfolio-variety avoidance)

This developer's public flagships (`privacy-forge`, `laravel-consent-guard`)
also use Laravel/PostgreSQL, and the public track's governance rule
deliberately pushes *against* repeating a stack across public repositories
(to demonstrate range to a reviewer). **That rule does not apply here, and
repeating the stack here is the correct call, not an exception being
grudgingly allowed:**

- This is a price-sensitive market (see `00-project-brief.md`'s business
  assumptions) where the deciding factor for viability is delivery speed
  and low ongoing operating cost, not technology novelty. A public
  portfolio repo is evaluated by a reviewer looking at *how* it was built;
  this product will be evaluated by a small-business owner deciding whether
  a monthly fee is worth it — nobody buying this product cares what backend
  framework runs it. Learning an unfamiliar stack for this product would
  spend scarce build time on the wrong problem — the risk worth taking on
  is Stripe Connect and multi-tenancy (both genuinely new), not the web
  framework underneath them.
- PostgreSQL specifically is also a deliberate technical fit, independent
  of reuse: its exclusion-constraint mechanism (below) directly solves this
  product's one genuinely hard correctness problem (double-booking) at the
  database layer, not just PostgreSQL being "the reused choice."

## Why Nuxt for the frontend, not Inertia (a from-scratch decision)

`privacy-forge` uses Laravel + Vue via Inertia — a tightly coupled
monolith-style frontend. This product deliberately does **not** reuse that
pattern, for a real reason specific to this product's shape, not for
portfolio variety:

- The public-facing booking page is the product's single most
  performance- and SEO-sensitive surface. It's typically reached via an
  Instagram bio link or a Google Business Profile link, on mobile data, by
  a customer who will bounce if it's slow — this is a page that benefits
  concretely from server-side rendering and good Core Web Vitals in a way
  privacy-forge's authenticated, internal-tool-style UI never needed to.
  Inertia's model (full-page server-driven navigation, not really designed
  for public SSR/SEO the way Nuxt is) is a worse fit for *this specific
  page*, even though it was the right fit for privacy-forge's use case.
- A decoupled Laravel API + Nuxt frontend also leaves the door open to a
  future native or owner-facing mobile app (a plausible paid-expansion
  item, see `01-scope-and-non-goals.md`) without a backend rework, since
  the API doesn't assume a specific frontend rendering model the way an
  Inertia-coupled backend does.
- The trade-off accepted: a decoupled API is more upfront integration work
  (auth token handling, CORS, a real API contract) than Inertia's
  same-process convenience. This is accepted because the SSR/SEO need is
  concrete and the integration overhead is a one-time cost, not a
  recurring one.

## Why Stripe Connect specifically (not plain Stripe payments)

This product is a **platform facilitating payment from a business's
customer to that business**, with the platform taking a fee — that is
exactly Stripe Connect's purpose, not plain Stripe (which assumes the
platform itself is the merchant of record). Express accounts (not Standard
or Custom) are the right onboarding tier for a self-serve, non-technical
small-business owner: Express gives Stripe-hosted KYC/onboarding UI (fast,
low integration burden) at the cost of less white-labeling than Custom —
an acceptable trade-off at MVP where speed to a working payment flow
matters more than a fully branded onboarding experience.

## Double-booking prevention: PostgreSQL exclusion constraint

The one correctness property this product cannot get wrong is two
customers booking the same staff/resource for overlapping time windows —
a real race condition risk at any moment of concurrent demand (e.g., a
popular time slot getting hit by two booking requests near-simultaneously).
Application-level locking (e.g., a check-then-insert with a mutex) is
fragile under real concurrency and easy to get subtly wrong.

The recommended design (from scratch for this repository — not copied from
elsewhere in this developer's portfolio, though the general pattern of
"push a correctness invariant into the database rather than trusting
application code alone" mirrors this developer's tamper-evidence work in
`privacy-forge`): store each appointment's time window as a `tstzrange`
column, and add a PostgreSQL `EXCLUDE` constraint using the `btree_gist`
extension:

```sql
ALTER TABLE appointments
  ADD CONSTRAINT no_overlapping_appointments
  EXCLUDE USING gist (
    resource_id WITH =,
    appointment_range WITH &&
  );
```

This makes an overlapping booking for the same `resource_id` a hard
database-level rejection, not merely an application-level check that a bug
or a race condition could bypass — the same "correctness guarantee at the
data layer, not just the UI" philosophy this developer has already applied
to audit-log tamper-evidence, applied here to scheduling instead. Full
schema design (including how `resource_id` maps to staff vs. a future
equipment resource) is deferred to a future data-modeling session — see
`04-data-model.md`.

## Multi-tenancy: the genuinely new architectural risk

Unlike `privacy-forge` (deliberately single-organization, self-hosted per
instance), this product is inherently multi-tenant: many independent
studios' data lives in one running system. The direction assumed for now
is a single shared PostgreSQL database with a `tenant_id` (business ID)
column on every tenant-scoped table, enforced via ORM-level global scopes —
the same broad approach used by most Laravel multi-tenant SaaS products —
but this is the one area where "we've done this before" does not apply,
and it deserves dedicated, adversarial testing (analogous to how
`privacy-forge` exhaustively tests its ABAC authorization matrix) before
this product could be considered pilot-ready. See
`06-security-threat-model.md` and `01-scope-and-non-goals.md`'s Definition
of MVP complete.

## Deferred to future sessions

- Full ERD and migration design — `04-data-model.md`.
- API contract shape (REST vs. something else, versioning) —
  `05-api-contracts.md`.
- Reminder-delivery infrastructure detail (which email/SMS provider, retry/
  backoff design) — `08-deployment-and-operations.md`.
- Testing strategy, including how the tenant-isolation test suite is
  structured — `07-testing-strategy.md`.
