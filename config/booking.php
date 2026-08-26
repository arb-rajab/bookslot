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

];
