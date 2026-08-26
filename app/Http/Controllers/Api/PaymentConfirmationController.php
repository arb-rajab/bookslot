<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/bookings/{token}/confirm-payment (05-api-contracts.md endpoint
 * 3, D-0021). Built for real this session (D-0033) — previously a Session-9
 * stub that unconditionally flipped `pending_payment` to `confirmed` without
 * ever checking Stripe.
 *
 * D-0033's transaction boundary, the same shape D-0027/D-0030 already
 * validated for booking-creation, applied to this route's own Stripe call:
 *   1. A short `TenantContext::run()` to read the appointment and its
 *      deposit `payments` row.
 *   2. `PaymentIntentGateway::retrieve()` — re-checking what Stripe actually
 *      did with the deposit PaymentIntent — strictly OUTSIDE any open
 *      transaction.
 *   3. A second short `TenantContext::run()` to record the real outcome:
 *      on success, the appointment moves to `confirmed`, the `payments` row
 *      to `succeeded`, and `payment_mandates.stripe_payment_method_id`
 *      (D-0031, nullable-with-backfill) is filled in from Stripe's own
 *      response — the first code in this repository that ever writes that
 *      column. On a synchronous decline, the appointment is deliberately
 *      left in `pending_payment` (D-0021/J2 — the customer retries with a
 *      different card using the same token) and the `payments` row moves to
 *      `failed`, matching the `payment_intent.payment_failed` webhook
 *      mapping (05-api-contracts.md) so both paths agree on the same
 *      status.
 *
 * Deliberately NOT behind the `tenant.context` middleware (see
 * routes/api.php) — same reasoning as BookingController: that middleware
 * wraps the whole request in one transaction, which would hold a database
 * connection across the Stripe call for its full duration.
 *
 * Not single-use: repeated calls while `pending_payment` re-check Stripe
 * each time (supports J2's retry-with-a-different-card flow, since the
 * client re-confirms the PaymentIntent client-side between calls). Once the
 * appointment leaves `pending_payment`, further calls are an idempotent
 * echo of the current status — no second Stripe call, no second mutation.
 */
class PaymentConfirmationController extends Controller
{
    public function __construct(private readonly PaymentIntentGateway $paymentIntentGateway) {}

    public function store(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        /** @var array{appointment_id: string} $payload */
        $payload = $request->attributes->get('token_payload');

        $appointment = TenantContext::run(
            $tenantId,
            fn () => Appointment::query()->find($payload['appointment_id']),
        );

        if ($appointment === null) {
            return response()->json(['error' => 'INVALID_OR_EXPIRED_TOKEN'], 404);
        }

        if ($appointment->status === 'cancelled') {
            return response()->json(['error' => 'BOOKING_EXPIRED'], 409);
        }

        if ($appointment->status !== 'pending_payment') {
            return response()->json(['status' => $appointment->status]);
        }

        $payment = TenantContext::run(
            $tenantId,
            fn () => Payment::query()
                ->where('appointment_id', $appointment->id)
                ->where('type', 'deposit')
                ->firstOrFail(),
        );

        $paymentIntentStatus = $this->paymentIntentGateway->retrieve($payment->stripe_payment_intent_id);

        if ($paymentIntentStatus->succeeded()) {
            TenantContext::run($tenantId, function () use ($appointment, $payment, $paymentIntentStatus) {
                $appointment->status = 'confirmed';
                $appointment->save();

                $payment->status = 'succeeded';
                $payment->save();

                PaymentMandate::query()
                    ->where('appointment_id', $appointment->id)
                    ->update(['stripe_payment_method_id' => $paymentIntentStatus->paymentMethodId]);
            });

            return response()->json(['status' => 'confirmed']);
        }

        TenantContext::run($tenantId, function () use ($payment, $paymentIntentStatus) {
            $payment->status = 'failed';
            $payment->failure_code = $paymentIntentStatus->lastPaymentErrorCode;
            $payment->save();
        });

        return response()->json([
            'status' => 'pending_payment',
            'last_payment_error' => [
                'code' => $paymentIntentStatus->lastPaymentErrorCode,
                'message' => $paymentIntentStatus->lastPaymentErrorMessage,
            ],
        ]);
    }
}
