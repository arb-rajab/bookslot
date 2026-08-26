<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET/PATCH /api/owner/appointments... (05-api-contracts.md endpoint 4,
 * built this session). Owner sees every one of their tenant's
 * appointments — D-0013's "own bookings only" narrowing applies to staff,
 * not the owner (see this session's D-0042). Gated by `auth`,
 * `auth.tenant`, `role:owner` (D-0029; consolidated into one middleware by
 * D-0043) — the whole request already runs inside one DB transaction
 * scoped to the owner's own tenant (BelongsToTenant + RLS), so no manual
 * TenantContext::run() is needed here, unlike BookingController/
 * PaymentConfirmationController: this controller never calls Stripe.
 */
class AppointmentController extends Controller
{
    /**
     * D-0042: only `confirmed` is a valid prior state for either target
     * status, per 04-data-model.md's booking state machine
     * (`confirmed --> completed`, `confirmed --> no_show` are the only two
     * incoming transitions either status has). `cancelled` is a documented
     * third target for this same endpoint in 05-api-contracts.md but is
     * deliberately not accepted here — this session's scope is attended/
     * no-show disposition only (D-0006: bookkeeping on already-captured
     * funds, no Stripe call); owner-initiated cancellation is a distinct
     * workflow (J7) with its own refund considerations, left for a future
     * session.
     */
    private const VALID_TARGET_STATUSES = ['completed', 'no_show'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending_payment', 'confirmed', 'completed', 'no_show', 'cancelled'])],
        ]);

        $query = Appointment::query()->with(['customer:id,name', 'service:id,name', 'staff:id,display_name']);

        if (isset($validated['from'])) {
            $query->where('starts_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('starts_at', '<=', $validated['to']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $appointments = $query->orderBy('starts_at')->get();

        return response()->json([
            'appointments' => $appointments->map(fn (Appointment $appointment) => $this->present($appointment)),
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::VALID_TARGET_STATUSES)],
        ]);

        // Tenant-scoped by BelongsToTenant + RLS, same as every other
        // controller in this codebase — an id belonging to another tenant
        // is indistinguishable from a nonexistent one at this query, so it
        // resolves to the same 404 rather than a 403 that would require an
        // out-of-scope cross-tenant existence check to produce.
        $appointment = Appointment::query()->find($id);

        if ($appointment === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        if ($appointment->status !== 'confirmed') {
            return response()->json(['error' => 'INVALID_STATUS_TRANSITION'], 409);
        }

        $fromStatus = $appointment->status;
        $appointment->status = $validated['status'];
        $appointment->save();

        // D-0006's disposition, recorded into the audit trail 04 names for
        // exactly this purpose: completed -> deposit applied to balance;
        // no_show -> deposit forfeited per studio policy. Both are pure
        // bookkeeping — no payments/refunds row is created, no Stripe call
        // is made; the disposition IS this status transition plus this
        // event row.
        BookingEvent::query()->create([
            'appointment_id' => $appointment->id,
            'actor_type' => 'owner',
            'actor_id' => $request->user()->id,
            'event_type' => 'status_changed',
            'from_status' => $fromStatus,
            'to_status' => $appointment->status,
        ]);

        return response()->json($this->present($appointment->fresh(['customer', 'service', 'staff'])));
    }

    /** @return array<string, mixed> */
    private function present(Appointment $appointment): array
    {
        $depositStatus = Payment::query()
            ->where('appointment_id', $appointment->id)
            ->where('type', 'deposit')
            ->value('status');

        return [
            'id' => $appointment->id,
            'status' => $appointment->status,
            'starts_at' => $appointment->starts_at,
            'ends_at' => $appointment->ends_at,
            'customer_name' => $appointment->customer?->name,
            'service_name' => $appointment->service?->name,
            'staff_name' => $appointment->staff?->display_name,
            'deposit_status' => $depositStatus,
        ];
    }
}
