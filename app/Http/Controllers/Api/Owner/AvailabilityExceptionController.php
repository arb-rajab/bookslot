<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityException;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/POST/DELETE /api/owner/staff/{staff}/availability-exceptions
 * (05-api-contracts.md endpoint 7, D-0051). One-off blocks/holidays layered
 * on top of a staff member's recurring working hours — `GET /api/tenants/
 * {slug}/availability` (D-0039) already reads `availability_exceptions`;
 * this is the owner-facing management surface for rows it was reading with
 * no way to write, other than a direct DB seed.
 */
class AvailabilityExceptionController extends Controller
{
    public function index(string $staff): JsonResponse
    {
        $staffModel = Staff::query()->find($staff);

        if ($staffModel === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        return response()->json([
            'availability_exceptions' => AvailabilityException::query()
                ->where('staff_id', $staffModel->id)
                ->orderBy('date')
                ->get(),
        ]);
    }

    public function store(Request $request, string $staff): JsonResponse
    {
        $staffModel = Staff::query()->find($staff);

        if ($staffModel === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'is_available' => ['required', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'reason' => ['nullable', 'string'],
        ]);

        $exception = AvailabilityException::create(array_merge($validated, [
            'tenant_id' => $staffModel->tenant_id,
            'staff_id' => $staffModel->id,
        ]));

        return response()->json($exception, 201);
    }

    public function destroy(string $staff, string $exception): JsonResponse
    {
        $staffModel = Staff::query()->find($staff);

        if ($staffModel === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $model = AvailabilityException::query()->where('staff_id', $staffModel->id)->find($exception);

        if ($model === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $model->delete();

        return response()->json(['status' => 'deleted']);
    }
}
