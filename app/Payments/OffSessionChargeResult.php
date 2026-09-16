<?php

namespace App\Payments;

/**
 * The minimal shape PaymentIntentGateway::chargeOffSession() (D-0057,
 * J5's balance charge) needs to return — deliberately not the full Stripe
 * SDK object/exception, same reasoning as PaymentIntentResult/
 * PaymentIntentStatus/RefundResult. `status` is `succeeded` or `failed`
 * only (never `requires_action`-shaped — an off-session confirm that needs
 * authentication the customer can't complete is itself a failure, per
 * Stripe's own off-session semantics; `failureCode` carries which kind,
 * e.g. `authentication_required` vs `card_declined`, so the controller can
 * map it onto 05-api-contracts.md endpoint 6's documented `failure_code`
 * field). `paymentIntentId` is always present (even on failure) since
 * `payments.stripe_payment_intent_id` is `NOT NULL` — Stripe's own
 * off-session confirm failure always carries the PaymentIntent it failed
 * to confirm.
 */
final class OffSessionChargeResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $paymentIntentId,
        public readonly ?string $failureCode,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
