<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BookingEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/POST /api/bookings/manage/{token}[/cancel] (05-api-contracts.md):
 * look up or cancel a booking via the signed manage-booking link, no login.
 * The token's own appointment_id (set onto the request by
 * ResolveTenantFromSignedToken) is the sole identifier — never a
 * route-supplied raw id. Both actions share the same token/tenant
 * resolution (`resolve.tenant.token:manage_booking` + `tenant.context`,
 * routes/api.php) — this is deliberately the existing `manage_booking`
 * token, not a new token purpose: it is already minted at booking-creation
 * time (BookingController::store) and handed to the customer, so reusing it
 * here needs no new issuance path and no new secret-guessing surface (a
 * cancelled/expired/tampered token fails identically to `show()`'s, per
 * D-0021 — no separate raw id ever appears in this request to leak against).
 *
 * 05 doesn't yet detail this endpoint's response body (its own "sketch, not
 * exhaustive" scope, per its Deferred section) — this returns the minimum
 * fields the endpoint's stated purpose ("look up a booking") requires; the
 * full shape is still open for whichever session next has 05 in scope.
 */
class ManageBookingController extends Controller
{
    /**
     * Mirrors OwnerAppointmentController::CANCELLABLE_STATUSES (D-0051) —
     * a terminal status (`completed`, `no_show`, already-`cancelled`) has
     * nothing left to cancel, for a customer exactly as for the studio.
     */
    private const CANCELLABLE_STATUSES = ['pending_payment', 'confirmed'];

    public function show(Request $request): JsonResponse
    {
        $appointment = $this->resolveAppointment($request);

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

    /**
     * D-0052: the customer-facing counterpart of
     * OwnerAppointmentController::cancel(), same bookkeeping-only
     * discipline (no Stripe call, no `refunds` row — D-0036 keeps Stripe
     * test-mode-only and this endpoint doesn't touch it at all) — a
     * customer who wants their deposit back still has no self-service way
     * to get one; only the studio's still-unbuilt refund endpoint
     * (05-api-contracts.md endpoint 5) would do that. Deliberately not
     * wired to any no-show logic (J4 stays closed): this is always an
     * explicit customer action on their own token, never inferred.
     */
    public function cancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $appointment = $this->resolveAppointment($request);

        if ($appointment === null) {
            return response()->json(['error' => 'INVALID_OR_EXPIRED_TOKEN'], 404);
        }

        if (! in_array($appointment->status, self::CANCELLABLE_STATUSES, true)) {
            return response()->json(['error' => 'INVALID_STATUS_TRANSITION'], 409);
        }

        $reason = $validated['reason'] ?? null;
        $fromStatus = $appointment->status;

        $appointment->status = 'cancelled';
        $appointment->cancelled_by = 'customer';
        $appointment->cancelled_reason = $reason;
        $appointment->cancelled_at = now();
        $appointment->save();

        BookingEvent::query()->create([
            'appointment_id' => $appointment->id,
            'actor_type' => 'customer',
            'actor_id' => null,
            'event_type' => 'status_changed',
            'from_status' => $fromStatus,
            'to_status' => 'cancelled',
            'metadata' => $reason !== null ? ['reason' => $reason] : null,
        ]);

        return response()->json([
            'appointment_id' => $appointment->id,
            'status' => $appointment->status,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ]);
    }

    private function resolveAppointment(Request $request): ?Appointment
    {
        /** @var array{appointment_id: string} $payload */
        $payload = $request->attributes->get('token_payload');

        return Appointment::query()->find($payload['appointment_id']);
    }
}
