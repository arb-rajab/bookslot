<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/bookings/manage/{token} (05-api-contracts.md): look up a booking
 * via the signed manage-booking link, no login. The token's own
 * appointment_id (set onto the request by ResolveTenantFromSignedToken) is
 * the sole identifier — never a route-supplied raw id.
 *
 * 05 doesn't yet detail this endpoint's response body (its own "sketch, not
 * exhaustive" scope, per its Deferred section) — this returns the minimum
 * fields the endpoint's stated purpose ("look up a booking") requires; the
 * full shape is still open for whichever session next has 05 in scope.
 */
class ManageBookingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var array{appointment_id: string} $payload */
        $payload = $request->attributes->get('token_payload');

        $appointment = Appointment::query()->find($payload['appointment_id']);

        if ($appointment === null) {
            return response()->json(['error' => 'INVALID_OR_EXPIRED_TOKEN'], 404);
        }

        return response()->json([
            'appointment_id' => $appointment->id,
            'status' => $appointment->status,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ]);
    }
}
