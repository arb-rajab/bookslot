# Changelog

All notable changes to this project will be documented in this file. Format
is loosely based on [Keep a Changelog](https://keepachangelog.com/), adapted
for a private, pre-code project that does not run full semver ceremony (see
the `v0.1.0-mvp-checkpoint` entry below for why this project tags at all).

## [Unreleased]

Nothing pending beyond the checkpoint below as of Session 18.

## [v0.1.0-mvp-checkpoint] - 2026-08-26

This tag marks Session 18's deliberate pause of the MVP build phase — not a
release in any conventional sense (this is a private repository with no
package consumers), but a durable pointer to the exact commit an honest,
final accounting of the project's state describes. See
`docs/project-memory/12-session-handoff.md`'s "State of bookslot" section
and `docs/project-memory/09-decision-log.md`'s D-0045 for the full
reasoning behind stopping here.

### Added (cumulative, Sessions 2 through 18 — not previously logged here)
- Laravel API backend: tenant-scoped schema and migrations, row-level
  tenant isolation enforced at both the application layer and via Postgres
  Row-Level Security (fail-closed by construction), a dedicated and
  exhaustively tested tenant-isolation suite.
- Public booking flow: derived-availability slot picker, deposit capture
  via Stripe (immediate-capture PaymentIntent, fake-gateway tier only — see
  Known limitations), booking creation with a database-enforced
  double-booking/buffer guarantee proven under real concurrency.
- Payment confirmation endpoint and its mandate-evidence write-back.
- Owner dashboard: authenticated appointment listing, mark-attended/
  mark-no-show with a real audit trail.
- A first Nuxt frontend (`frontend/`, monorepo) implementing the public
  booking page and the owner dashboard against the real backend.
- `mandates:reconcile-backfill`: an hourly reconciliation check guarding
  against a silent database/Stripe payment-method divergence.

### Not built (stated plainly, not implied "almost done")
- Automated reminders, automatic balance charging, the post-appointment
  rebooking prompt, Stripe Connect onboarding, hold-window expiry
  enforcement, and the owner-dashboard no-show count.

### Known limitations (permanent, by deliberate project-scope choice)
- Real Stripe network behavior has never been exercised and never will be
  within this project's lifecycle — real test-mode credentials were
  explicitly, permanently descoped.
- The frontend's client-side code has never executed in a real browser —
  proven only at the HTTP-protocol level and via SSR.

Full detail for every item above: `docs/project-memory/01-scope-and-non-goals.md`'s
MVP boundary checklist.

## [Session 0/1] - 2026-08-23

### Added
- Repository governance scaffold (LICENSE, CONTRIBUTING.md, SECURITY.md,
  CHANGELOG.md).
- `docs/project-memory/` Project Memory Pack (Session 0/1 — Discovery and
  Business Framing): project brief, scope and non-goals, initial
  architecture direction, and a lightweight decision log.
- No application code yet — this was a business-framing and
  governance-only session.
