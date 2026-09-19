<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login rate limiting (owner/staff login, `POST /api/tenants/{slug}/login`,
    | and platform-admin login, `POST /api/admin/login` — both share the
    | `login` named limiter registered in AppServiceProvider::boot())
    |--------------------------------------------------------------------------
    |
    | Session 37: neither login endpoint had ever had any throttling —
    | `09-decision-log.md` (D-0029's own entry, line ~528) explicitly raised
    | this as an unbuilt, real gap rather than inventing it silently, and it
    | sat unaddressed since Session 10. Two limits apply together, the
    | standard shape Laravel's own Fortify package uses for this same
    | problem:
    |
    | - `per_credential_per_minute`: keyed on (lowercased email + IP) — stops
    |   a targeted brute-force/credential-stuffing attempt against one known
    |   email address, while staying high enough that a real user mistyping
    |   a password a few times in a row is never locked out.
    | - `per_ip_per_minute`: keyed on IP alone — stops one source spraying
    |   many *different* email addresses quickly (enumeration / distributed
    |   credential stuffing), which the per-credential limit alone doesn't
    |   catch since each individual email only ever gets a few attempts.
    |   Set higher than the per-credential limit so it only engages once an
    |   IP is clearly not just one user retrying their own password.
    |
    | Both login routes share one limiter rather than two separate ones —
    | same credential-lookup shape (AuthController::login/adminLogin), same
    | threat model, and 05-api-contracts.md documents both under one
    | `{"error": "INVALID_CREDENTIALS"}` convention already; splitting the
    | throttle by route would be a distinction with no real difference here.
    |
    | 10/30 rather than Fortify's conventional 5/20 default: verified
    | directly against this repo's own real-HTTP Playwright E2E suite
    | (`frontend/tests/e2e/`), which re-authenticates the SAME seeded
    | `owner@demo-studio.test` credential from the same loopback IP roughly
    | 9 times across its four spec files in a single sequential
    | (`workers: 1`) run against one real, persistent (Redis-backed) cache —
    | a real, legitimate reuse pattern this project's own test suite already
    | has, not a hypothetical one. A stock 5/minute credential limit would
    | make that suite intermittently 429 partway through with no code bug
    | involved. 10 keeps meaningful brute-force protection (an online
    | guessing attack is still capped at 10 tries/minute against one
    | account) while giving headroom above the suite's own real usage.
    |
    */

    'login' => [
        'per_credential_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_CREDENTIAL_PER_MINUTE', 10),
        'per_ip_per_minute' => (int) env('RATE_LIMIT_LOGIN_PER_IP_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public booking-creation rate limiting
    | (`POST /api/tenants/{slug}/bookings`, BookingController::store)
    |--------------------------------------------------------------------------
    |
    | Session 37: the highest-priority gap named this session — this
    | endpoint is completely unauthenticated (D-0009) and triggers one real
    | Stripe PaymentIntent creation call per request (D-0006/D-0030), so an
    | unthrottled hit is both a real per-request cost (a live call to a paid
    | external API) and a hold-window-exhaustion abuse vector (FR-03's
    | pending_payment hold can be used to lock out a slot for
    | `booking.hold_window_minutes` with no payment ever completed) — both
    | already named, unaddressed, in `05-api-contracts.md`'s own Deferred
    | section since Session 6.
    |
    | Keyed on (IP + tenant slug), not IP alone: this is a per-tenant
    | resource (a specific studio's calendar and a specific studio's Stripe
    | Connect account absorb the abuse), so one IP hammering tenant A must
    | not also throttle that same IP's legitimate, unrelated booking attempt
    | against tenant B a moment later — the two are genuinely independent
    | actions sharing nothing but the caller's network address.
    |
    | Two tiers, same reasoning shape as the login limiter above: a tight
    | per-minute burst limit catches a fast scripted hit; a looser per-hour
    | limit catches a slower, sustained drip that would otherwise stay under
    | the per-minute threshold indefinitely.
    |
    | 10/50 rather than a tighter value: verified directly against the same
    | Playwright E2E suite named above, which creates roughly 6-7 real
    | bookings against the one seeded `demo-studio` tenant across its spec
    | files in the same single-worker run — a real, legitimate reuse
    | pattern, not a hypothetical one. 10/minute still caps a scripted
    | attacker's real Stripe-API-cost exposure and hold-window-exhaustion
    | blast radius tightly (at most 10 PaymentIntents/holds per tenant per
    | minute), and the 50/hour ceiling bounds sustained low-and-slow abuse
    | far below what an unthrottled endpoint would allow, while both stay
    | comfortably above the suite's own real usage.
    |
    */

    'booking' => [
        'per_minute' => (int) env('RATE_LIMIT_BOOKING_PER_MINUTE', 10),
        'per_hour' => (int) env('RATE_LIMIT_BOOKING_PER_HOUR', 50),
    ],

];
