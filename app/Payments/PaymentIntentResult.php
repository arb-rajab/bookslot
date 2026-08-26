<?php

namespace App\Payments;

/**
 * The minimal shape the booking-creation path (BookingController, D-0030)
 * needs back from a PaymentIntent creation call — deliberately not the
 * full Stripe SDK object, so PaymentIntentGateway's contract doesn't leak
 * Stripe's own types into calling code.
 */
final class PaymentIntentResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $clientSecret,
    ) {}
}
