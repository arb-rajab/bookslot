# Requirements
> Purpose: testable statements of what the system must do and how well.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1 — light pass; a full requirements
> session is deferred, see note below)

This session is business framing, not requirements analysis — a dedicated
future session should own the full functional/non-functional requirements
pass (roles matrix, numbered FR/NFR tables with acceptance criteria, data
classification table) the way `01-scope-and-non-goals.md`'s MVP boundary
checklist implies. What follows is a light sketch of the critical workflow
as user stories, enough to keep `03-architecture.md`'s reasoning grounded,
not a substitute for that future pass.

## Roles (sketch)

| Role | Can | Cannot |
|---|---|---|
| Owner | manage services, staff, deposit rules, view all bookings/payments for their business, mark no-shows/balances | see other businesses' data |
| Staff | view their own upcoming bookings | manage services/pricing, see other staff's bookings (TBD — may be allowed at MVP; a future session should decide) |
| Customer | book a slot, pay a deposit, receive reminders, pay/see balance | see the owner dashboard or any other customer's data |

## Critical workflow, as user stories

1. **As a customer**, I can view a business's public booking page, pick a
   service and an available time slot, so that I can request an
   appointment.
2. **As a customer**, I pay a deposit (fixed amount or percentage,
   configured by the business) at booking time, so that my slot is held.
3. **As a customer**, I receive automated reminders before my appointment
   (email and/or SMS, at owner-configured intervals), so that I don't
   forget it.
4. **As an owner**, after the appointment, I can mark it attended or a
   no-show, so that the deposit's disposition (forfeit/refund/apply to
   balance) is recorded per my studio's policy.
5. **As a customer**, my remaining balance is charged automatically (or
   marked paid in person by the owner), so that the transaction is
   complete without manual invoicing.
6. **As a customer**, after a completed appointment, I receive a rebooking
   prompt, so that returning is a one-click action.
7. **As an owner**, I can see all of the above — upcoming bookings, deposit/
   balance status, no-show history — on a single dashboard, so that I don't
   need to cross-reference Instagram DMs and a payment app.

## Deferred to a future requirements session

- Full acceptance criteria per story.
- Numbered functional and non-functional requirements tables.
- Data classification table (what's PII, what's payment metadata, retention
  per field).
- Integration requirements (specific email/SMS provider contracts).
- Constraints (e.g., minimum notice period for booking/cancellation).
