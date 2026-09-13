<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/owner/notifications (D-0051) — the reminder delivery log/status
 * view the admin frontend needs. D-0050 (Session 19) built real sending
 * (`reminders:dispatch` + `SendAppointmentReminderJob`) but no way for an
 * owner to see what actually went out; this is that read surface, across
 * the whole tenant rather than one appointment at a time (the per-
 * appointment view lives on `AppointmentController::show()`).
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['scheduled', 'sent', 'failed', 'cancelled'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = NotificationDelivery::query()->with(['appointment.customer:id,name']);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $deliveries = $query->orderByDesc('scheduled_for')->limit($validated['limit'] ?? 50)->get();

        return response()->json([
            'notifications' => $deliveries->map(fn (NotificationDelivery $delivery) => [
                'id' => $delivery->id,
                'appointment_id' => $delivery->appointment_id,
                'customer_name' => $delivery->appointment?->customer?->name,
                'purpose' => $delivery->purpose,
                'channel' => $delivery->channel,
                'scheduled_for' => $delivery->scheduled_for,
                'sent_at' => $delivery->sent_at,
                'status' => $delivery->status,
            ]),
        ]);
    }
}
