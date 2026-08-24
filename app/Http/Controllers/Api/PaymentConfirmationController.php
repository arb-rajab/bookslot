<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/bookings/{token}/confirm-payment (05-api-contracts.md,
 * redesigned by D-0021). No Stripe call this session — out of this
 * session's scope. The actual PaymentIntent confirmation is stubbed at
 * exactly this boundary: a pending_payment appointment is simply moved to
 * confirmed, standing in for what a real synchronous Stripe confirmation
 * result would otherwise drive. Whoever wires real Stripe confirmation
 * replaces the body of the `pending_payment` branch below; the token
 * verification and idempotency shape around it does not need to change.
 *
 * Idempotent per D-0021: repeated calls while pending_payment succeed
 * identically; once confirmed, a further call echoes the same status
 * rather than mutating again; once cancelled (hold window elapsed), 409
 * BOOKING_EXPIRED.
 */
class PaymentConfirmationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var array{appointment_id: string} $payload */
        $payload = $request->attributes->get('token_payload');

        $appointment = Appointment::query()->find($payload['appointment_id']);

        if ($appointment === null) {
            return response()->json(['error' => 'INVALID_OR_EXPIRED_TOKEN'], 404);
        }

        if ($appointment->status === 'cancelled') {
            return response()->json(['error' => 'BOOKING_EXPIRED'], 409);
        }

        if ($appointment->status === 'pending_payment') {
            $appointment->status = 'confirmed';
            $appointment->save();
        }

        return response()->json(['status' => $appointment->status]);
    }
}
