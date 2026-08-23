# Project Brief
> Purpose: the single source of truth for what this project is and why it exists.
> Project: bookslot (PRIVATE track — working name, not a final commitment)
> Last updated: 2026-08-23 (Session 0/1 — Discovery and Business Framing)
> Status: DRAFT — first pass, not yet exercised against a real design partner.

## A note on track and governance (read this first)

This is a **private-track** repository, not a public portfolio flagship.
That changes several things this file would otherwise inherit unchanged
from the public pattern (see `privacy-forge`, `laravel-consent-guard`):

- **No framework-allocation-ledger check applies.** The public track's Rule
  D1 ("ledger before architecture") exists to force deliberate technology
  *variety* across public portfolio repositories, so a reviewer sees range
  of skill rather than one stack repeated everywhere. That rule has no
  bearing on a private commercial product — here, reusing a proven stack
  (Laravel/PostgreSQL, matching what's already shipped in this developer's
  other work) is the *correct* business decision, not corner-cutting. See
  `09-decision-log.md` for the explicit reasoning. Consequently, this
  repository has no `00a-ledger-confirmation.md` file — that file is a
  public-track artifact and does not apply here.
- **The Project Memory Pack is adapted, not force-fit.** The 15-file
  pattern (`00`–`14`) is kept for consistency with this developer's other
  work, but several files that assume a *public portfolio artifact* don't
  translate directly to a private product with no design-partner pilot yet:
  - There is no case study, because there is no finished, demonstrable
    product or real customer outcome to write one about.
  - There is no `docs/SDLC-EVIDENCE.md` public evidence map, because that
    document exists to help an external reviewer (a hiring manager, a
    portfolio visitor) quickly judge SDLC rigor across a *public* repository
    — there is no such external reviewer audience for private commercial
    code.
  - Files like `04-data-model.md`, `05-api-contracts.md`,
    `07-testing-strategy.md`, and `08-deployment-and-operations.md` are
    intentionally light stubs this session — they belong to implementation
    sessions that haven't happened yet, not to a discovery/business-framing
    session. Where the business framing below already implies a concrete
    technical direction (e.g., the PostgreSQL exclusion-constraint pattern
    for double-booking), that direction is recorded as a forward pointer in
    the relevant stub rather than invented in full ahead of the session that
    should actually own it.

## A note on the sanitized-AI-session protocol (read this second)

This developer's broader portfolio governance calls for discussing private
business logic with AI assistants only via sanitized briefs (placeholder
terms, no real figures) once real customer or business data exists, to
avoid pasting confidential commercial detail into a third-party AI
session. **That protocol is not yet load-bearing for this repository.**

As of this session (2026-08-23):
- There is no real pilot customer.
- There is no real pricing.
- There is no real production data of any kind.

Everything in this Project Memory Pack — the vertical chosen, the target
buyer, the assumptions, the numbers — is **illustrative and hypothetical
business modeling**, reasoned through as if this were a real decision, but
not derived from or referencing any real business relationship. There is
nothing to sanitize yet, because there is nothing real yet.

**This changes the moment a real design-partner pilot begins.** That is the
explicit trigger for a future session to actually *exercise* the
sanitized-session protocol for real — sanitizing a real brief before
discussing it in an AI session — rather than merely describing that the
protocol exists, as this file does now. Whoever runs that future session
should treat "a real pilot studio has agreed to try this" as the signal to
stop treating business detail in this repository as freely discussable and
start sanitizing it, and should update this note once that happens.

## One-line description

Booking, deposits, and no-show protection for small appointment-based
service businesses — a customer picks a service and time slot, pays a
deposit up front, gets reminded automatically, and the business collects
the remaining balance and gets a rebooking prompt afterward, without the
owner manually chasing any of it over text and e-transfer.

## Concrete illustrative vertical: tattoo studios

The brief below is written against one concrete vertical — independent and
small (1–6 chair) **tattoo studios** — rather than "appointment businesses"
in the abstract, because a vague buyer produces vague product decisions.
Driving instructors, hair/beauty salons, and trades are structurally
similar (appointment + deposit + no-show risk) and are plausible expansion
markets, but tattoo studios are the sharpest starting point for three
concrete reasons:

1. **Deposit culture already exists and is buyer-accepted.** Tattoo
   customers already expect to pay a deposit to hold a booking — the
   product formalizes and automates an existing habit (usually collected
   ad hoc via Instagram DM + e-transfer or cash) rather than needing to
   convince the market that deposits are normal. That is a materially
   easier sell than a vertical where deposits would be a new ask.
2. **The cost of a no-show is concentrated and high per slot.** A tattoo
   appointment is typically a large, longer block of a single artist's
   time, often booked weeks in advance, sometimes requiring custom design
   prep beforehand. A no-show doesn't just lose a slot — it wastes prep
   work and can't easily be backfilled on short notice. This makes the
   no-show-protection value proposition viscerally obvious to the buyer,
   compared to a vertical with shorter, easily-rebooked slots.
3. **The workflow is structurally simple at MVP scope.** One artist per
   appointment (no complex multi-resource scheduling), a small, ownercurated
   service/style list, and a long lead time between booking and appointment
   (which is exactly what makes a multi-touchpoint reminder cadence valuable
   rather than a nice-to-have). This keeps the MVP's technical surface
   small while still being a complete, sellable product.

Hair/beauty salons and driving instructors are noted as adjacent look-alike
markets the same product likely serves with little change (see
`01-scope-and-non-goals.md`'s backlog), but they are **not** the vertical
this brief's reasoning is anchored to — sharpening decisions against one
concrete buyer beats designing for "appointment businesses in general."

## Problem statement

Independent tattoo studio owners currently run booking, deposits, and
no-show mitigation almost entirely by hand: consultations and bookings
happen over Instagram DMs or text, deposits are collected via e-transfer,
Venmo, or cash (tracked, if at all, in the owner's head or a notes app),
reminders are manual texts the owner has to remember to send, and there is
no rebooking touchpoint after the appointment beyond word of mouth.

This is not a daily inconvenience so much as a source of **direct, ongoing
revenue leakage that is invisible line-item-by-line-item but real in
aggregate**: every no-show is a lost booked slot (and, for a custom piece,
wasted design/prep time) that a formal deposit-and-reminder system would
have either prevented (a real financial deposit at stake raises show-up
rates) or at minimum compensated for (a forfeited deposit). The failure
mode compounds with the business's own growth — the busier the studio, the
more manual tracking breaks down, and the more a missed reminder or an
untracked deposit costs. This is exactly the shape of problem a
price-sensitive, time-poor small-business owner chronically under-invests
in fixing themselves: the tools to fix it (a generic scheduling SaaS, a
generic payments processor, wired together by hand) exist, but wiring them
together is itself a project most owners don't have the time or technical
background to do, so the status quo (Instagram + e-transfer) persists by
default, not by preference.

## Target users and stakeholders

- **Primary user (buyer and admin):** the studio owner, or an owner-artist
  at a small multi-chair studio. Not technical, price-sensitive, currently
  paying nothing for a formal booking tool and unlikely to accept a large
  monthly fee — the product must earn its cost through directly visible,
  attributable no-show reduction and time saved, not through abstract
  "professionalism" appeal.
- **Secondary user:** studio staff/artists at a multi-chair studio, who
  need visibility into their own upcoming bookings and deposit status but
  not necessarily the owner's full business view.
- **Primary user (customer-facing):** the studio's own customer, booking an
  appointment through a public-facing page (typically reached via an
  Instagram bio link or the studio's own website). This user has no
  account relationship with `bookslot` itself — their relationship is with
  the studio; `bookslot` is infrastructure they pass through, not a brand
  they choose.
- **Stakeholder (non-user, financial):** Stripe, as the payments
  infrastructure processing deposits and balances on the platform's behalf
  via Connect — their platform risk/compliance requirements (KYC on
  connected accounts, dispute handling) constrain onboarding design.

## Business assumptions (reasoned, not yet validated — no real pilot exists)

Unlike a session where a real design partner or real usage data exists to
validate against, every assumption below is a **reasoned hypothesis**, not
a confirmed fact. They are stated explicitly so a future session with real
pilot data can confirm or overturn them individually, rather than the whole
brief being silently treated as validated because it sounds plausible.

- **Willingness to pay:** a studio owner will pay a modest recurring fee
  (illustrative range, not a real price: low tens of USD/month) plus accept
  a small percentage on deposits/balances processed, *because* the
  alternative (manual tracking) has a real, already-felt cost they
  experience as lost bookings most months — not because a booking tool is
  inherently valuable in the abstract. **To validate:** would need a real
  pilot studio's actual willingness to pay, not owner enthusiasm alone.
- **Formalizing an existing habit, not introducing a new one:** the target
  buyer already asks for deposits manually. The product's pitch is
  automation and reduced admin burden on an accepted practice, not
  convincing the market deposits are acceptable. **To validate:** confirm
  this holds for the specific pilot studio's actual current practice,
  since some studios may not currently require deposits at all.
- **Segment boundary:** single-location, small (1–6 chair) independent
  studios are the addressable MVP segment. Multi-location chains/franchises
  are assumed out of reach for MVP — they likely need role hierarchies,
  cross-location reporting, and probably already use a more expensive
  vertical SaaS. **To validate:** confirmed only by explicitly not
  targeting a multi-location studio for the first pilot.
- **Stripe Connect onboarding friction is acceptable:** assumed that a
  studio owner will tolerate Stripe Connect Express's identity-verification
  onboarding flow in exchange for automated payouts. **To validate:** this
  is a real UX risk — some owners may already have a personal Stripe or
  Square account and resist a second one; a real pilot is needed to learn
  whether this is a minor friction or a genuine adoption blocker.
- **Reminder cadence reduces no-shows:** assumed that automated,
  multi-touchpoint reminders (e.g., ~7 days, ~24 hours, ~2 hours before)
  measurably reduce no-show rate relative to the status quo. **To
  validate:** requires before/after no-show-rate data from a real pilot
  studio — there is no such data yet, and none should be fabricated to look
  like there is.

## Why this project exists (private-track framing)

Unlike the public flagships, this repository does not exist to demonstrate
SDLC rigor to an external reviewer — it exists to explore a real commercial
product idea using the same disciplined, session-based, documented working
style this developer already uses (because that style works, not because a
portfolio audience demands it). Its purpose is:

- **Business objective:** validate, cheaply and quickly, whether a lean
  booking-and-deposits product for a price-sensitive, appointment-based
  small-business vertical is worth building past an MVP.
- **Why reused frameworks are the right call here, explicitly:** this is a
  price-sensitive market (see business assumptions above) where delivery
  speed and low ongoing operating cost decide whether the product is
  economically viable at all — a solo/small team spending learning budget
  on an unfamiliar stack for this product would be optimizing for the wrong
  thing. Laravel + PostgreSQL are already proven, fast-to-ship choices for
  this developer specifically (see `laravel-consent-guard`, `privacy-forge`).
  Reusing them here is a deliberate, examined business decision, not an
  unexamined default — see `09-decision-log.md`.

## Success metrics (to be measured once a real pilot exists — not real numbers yet)

Because no real pilot or usage exists, these are stated as **what will be
measured**, not as achieved numbers:

1. Time from a studio owner signing up to their public booking page being
   live and able to accept a real deposit: target under 30 minutes of setup
   effort, unassisted. (Illustrative target — to be confirmed against a
   real pilot's actual onboarding experience.)
2. Deposit-collection completion rate: the percentage of started bookings
   that complete a deposit payment, as a proxy for checkout-flow friction.
3. No-show rate for appointments booked and reminded through the product,
   compared to the pilot studio's own prior (self-reported, not audited)
   no-show rate — the core value-proposition metric, and the one most
   dependent on having a real pilot to measure against.
4. Pilot owner's qualitative willingness to keep paying after a free/trial
   period, as the most honest early signal of product-market fit for a
   price-sensitive buyer.

## Feasibility notes and key risks

- **Risk — no real design partner yet.** Every assumption above is
  reasoned, not validated. The single highest-priority next step for this
  product is recruiting one real pilot studio, not building more MVP
  features against untested assumptions. See `10-risk-register.md`.
- **Risk — multi-tenancy is genuinely new territory** relative to this
  developer's other work (`privacy-forge` is deliberately single-org/
  self-hosted). Per-tenant data isolation needs to be a first-class,
  explicitly tested concern from the first schema, not retrofitted. See
  `03-architecture.md` and `06-security-threat-model.md`.
- **Feasibility:** Laravel, PostgreSQL, Redis are established skills for
  this developer (low technical risk). Stripe Connect (as opposed to plain
  Stripe payments) and a decoupled Nuxt frontend are the two genuinely new
  pieces for this specific product — see `03-architecture.md` for why each
  is worth the added surface anyway.

## Elevator pitch

"bookslot is what an independent tattoo studio wishes their Instagram-DM-
and-e-transfer booking process actually was: a real booking page, a real
deposit taken automatically, reminders that go out without the owner
remembering to send them, and a rebooking nudge afterward — all for a
studio that doesn't have the time, budget, or technical background to wire
that together themselves."
