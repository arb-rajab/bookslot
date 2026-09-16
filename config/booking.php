<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pending-payment hold window
    |--------------------------------------------------------------------------
    |
    | D-0011 (docs/project-memory/09-decision-log.md): how long a
    | `pending_payment` appointment holds its slot before it's eligible to
    | be cancelled and the slot released. A configuration value, not a
    | hard-coded constant — read by both booking creation (to compute the
    | confirm-payment token's expiry, D-0021) and the still-unbuilt
    | scheduled expiry job, from this one source, so the two can never
    | drift apart. 15 is an explicit, provisional guess (D-0011), not
    | validated against real booking-funnel data.
    |
    */

    'hold_window_minutes' => (int) env('BOOKING_HOLD_WINDOW_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Confirm-payment token grace period
    |--------------------------------------------------------------------------
    |
    | D-0021: the payment_confirmation_token's own expires_at is set to
    | booking-creation time plus the hold window above, plus this small
    | fixed grace period — an independent, defense-in-depth expiry that
    | holds even if the hold-window-enforcement job itself fails to run.
    |
    */

    'confirm_payment_token_grace_minutes' => (int) env('BOOKING_CONFIRM_PAYMENT_TOKEN_GRACE_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Payment-mandate backfill reconciliation grace period
    |--------------------------------------------------------------------------
    |
    | D-0037 (docs/project-memory/09-decision-log.md), R-07
    | (10-risk-register.md): how long a `payment_mandates` row is allowed to
    | sit with `stripe_payment_method_id` still NULL before
    | `mandates:reconcile-backfill` (app/Console/Commands/
    | ReconcilePaymentMandatesCommand.php) flags it for human review.
    |
    | Reasoned from this project's own confirm-payment token lifetime, not
    | copied from D-0011's 15-minute hold window: the token that lets a
    | customer complete confirm-payment already dies at
    | hold_window_minutes + confirm_payment_token_grace_minutes above (15 + 5
    | = 20 minutes by default) — past that point the backfill's fate is
    | already sealed one way or the other, so flagging any earlier would
    | catch mandates still legitimately mid-flight through a normal booking
    | (exactly the false-positive D-0037 was written to avoid). 30 adds a
    | fixed 10-minute buffer on top of that 20-minute deadline for scheduler
    | cadence and clock skew, not because the underlying booking flow itself
    | needs more time.
    |
    */

    'reconciliation_grace_minutes' => (int) env('BOOKING_RECONCILIATION_GRACE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Availability lookahead and slot granularity
    |--------------------------------------------------------------------------
    |
    | 05-api-contracts.md's GET .../availability endpoint left both of these
    | as an explicit "product decision, not fixed here" until the endpoint
    | was actually built (this session). Provisional guesses, same status as
    | D-0011's hold window: 60 days is generous enough for a customer
    | planning ahead without letting a single request force this session's
    | derived (not materialized) slot algorithm to compute an unbounded
    | range; 15 minutes matches the finest granularity working_hours/
    | appointments already operate at elsewhere in this schema.
    |
    */

    'availability_max_lookahead_days' => (int) env('BOOKING_AVAILABILITY_MAX_LOOKAHEAD_DAYS', 60),

    'availability_slot_increment_minutes' => (int) env('BOOKING_AVAILABILITY_SLOT_INCREMENT_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Reminder cadence
    |--------------------------------------------------------------------------
    |
    | FR-06 (02-requirements.md), D-0050 (09-decision-log.md): each key is a
    | notification_deliveries.purpose value (see that table's CHECK
    | constraint), each value how many minutes before an appointment's
    | starts_at that reminder becomes due. The ~7 day/~24 hour/~2 hour
    | cadence itself is 01-scope-and-non-goals.md's own illustrative
    | default (R-04, unvalidated effectiveness) — unchanged by D-0050,
    | which only builds the sending mechanism, not a new cadence decision.
    |
    | 'reminder_dispatch_window_minutes' is how wide a band around the exact
    | offset above counts as "due now" when the scheduled command below
    | runs — reasoned from, and kept equal to, the command's own polling
    | frequency, so no appointment can fall between two runs and never get a
    | reminder, while a firstOrCreate on (appointment_id, purpose) keeps a
    | reminder that's due across more than one run from ever being
    | double-scheduled.
    |
    */

    'reminder_offsets_minutes' => [
        'reminder_7d' => 7 * 24 * 60,
        'reminder_24h' => 24 * 60,
        'reminder_2h' => 2 * 60,
    ],

    'reminder_dispatch_window_minutes' => (int) env('BOOKING_REMINDER_DISPATCH_WINDOW_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Public booking page base URL
    |--------------------------------------------------------------------------
    |
    | FR-23/D-0014/D-0059: the manual re-invite email (05-api-contracts.md
    | endpoint 9) links a customer back to their tenant's public booking
    | page (frontend/app/pages/tenants/[slug]/index.vue), the same page
    | D-0041 already built. Same FRONTEND_URLS-first-entry pattern
    | config/services.php's stripe.connect_onboarding_redirect_url already
    | uses, kept as its own key here (rather than a shared helper) since the
    | two serve genuinely different pages and this project's stated
    | preference is not to force an abstraction over two similar lines.
    |
    */

    'public_booking_base_url' => rtrim(explode(',', env('FRONTEND_URLS', 'http://localhost:3000'))[0], '/'),

];
