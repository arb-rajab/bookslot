<?php

namespace App\Payments;

/**
 * D-0027/D-0030 (docs/project-memory/09-decision-log.md): the seam
 * BookingController calls OUTSIDE any open database transaction — this
 * interface, not a concrete Stripe SDK call inline in the controller, is
 * what lets 07-testing-strategy.md's "faked Stripe client by default, a
 * narrower real-test-mode subtier" plan actually swap implementations
 * (StripePaymentIntentGateway for real Stripe test-mode traffic; a fake in
 * tests/Support for making the call take a while or fail on demand, per
 * D-0027's own validation requirement).
 */
interface PaymentIntentGateway
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function create(
        int $amountMinorUnits,
        string $currency,
        ?string $connectedAccountId,
        int $applicationFeeAmountMinorUnits,
        array $metadata,
    ): PaymentIntentResult;

    /**
     * D-0033: re-checks an already-created PaymentIntent's current state —
     * PaymentConfirmationController's read of what Stripe actually did
     * (succeeded / still needs a payment method / declined), never a second
     * `create()`.
     */
    public function retrieve(string $paymentIntentId): PaymentIntentStatus;

    /**
     * D-0056: refunds (fully or partially) an already-captured deposit
     * PaymentIntent — Owner\AppointmentController::refund(), 05-api-
     * contracts.md endpoint 5. `$reason` is this app's own free-text
     * `refunds.reason` value, never forwarded to Stripe's own constrained
     * `reason` enum (`duplicate`/`fraudulent`/`requested_by_customer`),
     * which this app's owner-facing reason text has no reliable mapping to.
     */
    public function refund(string $paymentIntentId, int $amountMinorUnits, ?string $reason): RefundResult;
}
