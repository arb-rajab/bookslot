<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/staff/appointments?from=&to= (05-api-contracts.md). Own
 * upcoming bookings only at MVP — resolved, not open (FR-16, D-0013): a
 * staff member never sees another staff member's bookings here, no matter
 * how the query params are set. Gated by `auth`, `role:staff`,
 * `auth.tenant`, `role:staff` (D-0029; consolidated into one middleware by
 * D-0043).
 */
class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $staff = Staff::query()->where('user_id', $request->user()->id)->first();

        if ($staff === null) {
            return response()->json(['appointments' => []]);
        }

        $query = Appointment::query()->where('staff_id', $staff->id);

        if (isset($validated['from'])) {
            $query->where('starts_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('starts_at', '<=', $validated['to']);
        }

        return response()->json([
            'appointments' => $query->orderBy('starts_at')->get([
                'id', 'staff_id', 'service_id', 'customer_id', 'starts_at', 'ends_at', 'status',
            ]),
        ]);
    }
}
