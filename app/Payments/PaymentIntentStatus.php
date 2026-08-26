<?php

namespace App\Payments;

/**
 * The minimal shape PaymentConfirmationController (D-0033) needs back from
 * re-checking an existing PaymentIntent — deliberately not the full Stripe
 * SDK object, same reasoning as PaymentIntentResult for `create()`.
 */
final class PaymentIntentStatus
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $paymentMethodId,
        public readonly ?string $lastPaymentErrorCode,
        public readonly ?string $lastPaymentErrorMessage,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
