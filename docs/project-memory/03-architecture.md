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

## Amendment (Session 2, 2026-08-24) — D1 and D3 resolved with specifics

This session (`02-requirements.md`, `04-data-model.md`, `09-decision-log.md`
D-0005/D-0006/D-0007) resolved the open decisions this file deferred. Recorded
here as amendments so the reasoning above isn't silently superseded:

- **Multi-tenancy enforcement is upgraded from app-scope-only to two layers.**
  The "ORM-level global scopes" sentence in the Multi-tenancy section above is
  no longer the whole picture: Postgres Row-Level Security (with `FORCE ROW
  LEVEL SECURITY`) is added as a fail-closed database-level backstop, keyed on
  a per-request/per-job session variable. See D-0005 in `09-decision-log.md`
  and `04-data-model.md` for the mechanism. The risk this file flags (§
  Multi-tenancy: the genuinely new architectural risk) is the reason for the
  upgrade, not a reason to leave it at app-scope-only.
- **The double-booking exclusion constraint is now fully specified,** not just
  directionally sketched. The example DDL above (`EXCLUDE USING gist
  (resource_id WITH =, appointment_range WITH &&)`) is superseded by the
  tenant-scoped, partial version in D-0007 and `04-data-model.md` — the
  original example was missing tenant scoping and the partial `WHERE` clause
  needed to let a cancelled slot be rebooked.
- **Deposit/payment mechanics (Stripe Connect usage in practice)** are now
  decided: immediate-capture PaymentIntent with a saved card for the later
  balance charge — see D-0006. This doesn't change the D-0003 account-type
  choice (Express) above, only how PaymentIntents are used against it.

## Amendment (Session 3, 2026-08-24) — RLS tenant-context lifecycle specified (D-0009)

Session 2's amendment above established *that* RLS backstops app-scope
tenancy; it didn't say *when or how* the session GUC (`app.current_tenant_id`)
is set and cleared, which review found was a real gap: `SET LOCAL` only
resets at `COMMIT`/`ROLLBACK`, so on a pooled or long-lived connection
(Horizon queue workers, PgBouncer) an ambient, non-transactional `SET` would
leak one request's or job's tenant context to whoever reuses that connection
next. Resolved this session, in full, in `09-decision-log.md` D-0009 and
`04-data-model.md`'s tenancy-boundary section — summarized here so this
file's architectural picture stays current:

- Every tenant-scoped HTTP request and every queued job runs inside an
  explicit database transaction, with the GUC set via a parameterized
  `set_config('app.current_tenant_id', ?, true)` call as its first
  statement — never a string-interpolated `SET LOCAL`.
- Three named Postgres roles: `bookslot_app` (the only role the running
  application ever authenticates as; ordinary, fully RLS-subject),
  `bookslot_migrator` (owns the tables, holds `BYPASSRLS`, used only by
  migrations/seeders/backfills/`pg_dump` — offline/CI-triggered, never
  request-triggered), and no third, live-request bypass role.
- The platform-admin cross-tenant path (FR-17) does **not** use a live
  `BYPASSRLS` role, revising this file's and D-0005's earlier
  `BYPASSRLS`-for-admin phrasing: it authenticates as `bookslot_app` and
  impersonates the specific tenant under an app-layer platform-admin
  authorization check, keeping RLS fail-closed even on that path.
- PgBouncer transaction-mode pooling is compatible and preferred (its
  connection-reclaim boundary matches the GUC's reset boundary exactly) —
  recorded as a forward constraint on `08-deployment-and-operations.md`'s
  eventual hosting choice, not resolved here.

## Deferred to future sessions

- Full ERD and migration design — `04-data-model.md`.
- API contract shape (REST vs. something else, versioning) —
  `05-api-contracts.md`.
- Reminder-delivery infrastructure detail (which email/SMS provider, retry/
  backoff design) — `08-deployment-and-operations.md`.
- Testing strategy, including how the tenant-isolation test suite is
  structured — `07-testing-strategy.md`.

## Amendment (Session 16, 2026-08-26) — repo layout decided: monorepo, `frontend/` in this same repository (D-0038)

D-0002 (this file's original recommendation, above) committed to a
decoupled Laravel API + Nuxt frontend but never settled *where the Nuxt
code lives* — a monorepo/separate-repo decision this file's original text
didn't address at all. This session, the first to write any Nuxt code,
needed an answer before scaffolding anything, and none was on record.

**Decided: monorepo — the Nuxt app lives at `frontend/` in this same
repository**, not a second `bookslot-frontend` repository. Recorded in full
as **D-0038** (`09-decision-log.md`). Short version: this is a solo-track
private repository (this file's own header), the two halves change
together constantly at this project's current stage (every session so far
that touched the API contract also had to touch whatever consumed it), and
a second repository would mean a second `git clone`, a second CI setup,
and cross-repo PR coordination for zero benefit this project's current
scale actually needs. `frontend/` is a fully independent Nuxt project
(its own `package.json`, `node_modules`, `.nuxt`/`.output` build
artifacts, all gitignored within `frontend/.gitignore`) — "monorepo" here
means "one `git` repository," not a shared build/dependency graph between
PHP and Node. The decoupled-API contract itself (D-0002) is unchanged by
this — `frontend/` still only ever talks to the Laravel API over real
HTTP, the same as a separate-repo frontend would, so this decision is
reversible later (splitting `frontend/` out via `git subtree`/`git
filter-repo` if the project ever grows a second team or a second
consumer of the API) without any API-shape rework.
