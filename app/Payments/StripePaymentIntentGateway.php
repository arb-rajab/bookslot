<?php

namespace App\Payments;

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
}
