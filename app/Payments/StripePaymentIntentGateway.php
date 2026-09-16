<?php

namespace App\Payments;

use Stripe\Exception\CardException;
use Stripe\StripeClient;

/**
 * D-0006's mechanics, for real: an immediate-capture PaymentIntent via a
 * Stripe Connect destination charge (`transfer_data[destination]` +
 * `application_fee_amount`), with `setup_future_usage: off_session` to
 * save the payment method for the later balance charge (J5). Not called
 * from inside any open database transaction — see BookingController and
 * D-0027.
 */
final class StripePaymentIntentGateway implements PaymentIntentGateway
{
    public function __construct(private readonly StripeClient $client) {}

    public function create(
        int $amountMinorUnits,
        string $currency,
        ?string $connectedAccountId,
        int $applicationFeeAmountMinorUnits,
        array $metadata,
    ): PaymentIntentResult {
        $params = [
            'amount' => $amountMinorUnits,
            'currency' => $currency,
            'setup_future_usage' => 'off_session',
            'metadata' => $metadata,
        ];

        if ($connectedAccountId !== null) {
            $params['transfer_data'] = ['destination' => $connectedAccountId];
            $params['application_fee_amount'] = $applicationFeeAmountMinorUnits;
        }

        $paymentIntent = $this->client->paymentIntents->create($params);

        return new PaymentIntentResult($paymentIntent->id, $paymentIntent->client_secret);
    }

    public function retrieve(string $paymentIntentId): PaymentIntentStatus
    {
        $paymentIntent = $this->client->paymentIntents->retrieve($paymentIntentId);

        return new PaymentIntentStatus(
            $paymentIntent->id,
            $paymentIntent->status,
            is_string($paymentIntent->payment_method) ? $paymentIntent->payment_method : null,
            $paymentIntent->last_payment_error->code ?? null,
            $paymentIntent->last_payment_error->message ?? null,
        );
    }

    public function refund(string $paymentIntentId, int $amountMinorUnits, ?string $reason): RefundResult
    {
        $refund = $this->client->refunds->create([
            'payment_intent' => $paymentIntentId,
            'amount' => $amountMinorUnits,
        ]);

        return new RefundResult($refund->id, $refund->status);
    }

    /**
     * D-0057: per Stripe's documented off-session-reuse-without-a-Customer
     * pattern — the same `payment_method` ID a `setup_future_usage:
     * off_session` PaymentIntent saved (D-0006's `create()` above) can be
     * confirmed directly against a NEW PaymentIntent via `off_session:
     * true, confirm: true`, without ever creating a Stripe Customer object.
     * This mirrors `create()`'s own deliberate choice not to pass a
     * `customer` param — this codebase has never created one, on either
     * side of this charge. `confirm()`-time failures (a decline, or SCA
     * that can't complete without the cardholder present) surface as a
     * thrown `CardException`, not a returned `requires_action` status —
     * Stripe's documented behavior for `off_session: true` confirms,
     * unlike the on-session confirm flow `retrieve()` above reads back
     * from. Never verified against real Stripe (D-0036) — written to
     * match Stripe's published API docs for this flow, exercised only via
     * the fake tiers.
     */
    public function chargeOffSession(
        string $paymentMethodId,
        int $amountMinorUnits,
        string $currency,
        ?string $connectedAccountId,
        int $applicationFeeAmountMinorUnits,
        array $metadata,
    ): OffSessionChargeResult {
        $params = [
            'amount' => $amountMinorUnits,
            'currency' => $currency,
            'payment_method' => $paymentMethodId,
            'off_session' => true,
            'confirm' => true,
            'metadata' => $metadata,
        ];

        if ($connectedAccountId !== null) {
            $params['transfer_data'] = ['destination' => $connectedAccountId];
            $params['application_fee_amount'] = $applicationFeeAmountMinorUnits;
        }

        try {
            $paymentIntent = $this->client->paymentIntents->create($params);

            return new OffSessionChargeResult('succeeded', $paymentIntent->id, null);
        } catch (CardException $e) {
            $error = $e->getError();
            $failedPaymentIntent = $error->payment_intent ?? null;

            return new OffSessionChargeResult(
                'failed',
                is_object($failedPaymentIntent) ? $failedPaymentIntent->id : ('pi_unknown_'.substr(md5(serialize($metadata)), 0, 16)),
                $error->code ?? 'card_declined',
            );
        }
    }
}
