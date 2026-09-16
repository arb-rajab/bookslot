<?php

namespace App\Payments;

/**
 * The minimal shape PaymentIntentGateway::refund() needs to return —
 * deliberately not the full Stripe SDK Refund object, same reasoning as
 * PaymentIntentResult/PaymentIntentStatus.
 */
final class RefundResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
    ) {}
}
