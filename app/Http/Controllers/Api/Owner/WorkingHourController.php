<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffWorkingHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET/PUT /api/owner/staff/{staff}/working-hours (05-api-contracts.md's
 * endpoint list, D-0051): a staff member's recurring weekly availability —
 * the input `GET /api/tenants/{slug}/availability` (D-0039) actually reads
 * from. `PUT` replaces the whole week in one call rather than exposing
 * per-row CRUD: a schedule is naturally edited as a whole ("Mon-Fri
 * 9-5"), and replace-the-set avoids the client having to diff against
 * whatever rows already exist.
 */
class WorkingHourController extends Controller
{
    public function index(string $staff): JsonResponse
    {
        $staffModel = Staff::query()->find($staff);

        if ($staffModel === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        return response()->json([
            'working_hours' => StaffWorkingHour::query()
                ->where('staff_id', $staffModel->id)
                ->orderBy('day_of_week')
                ->get(['id', 'day_of_week', 'start_time', 'end_time']),
        ]);
    }

    public function replace(Request $request, string $staff): JsonResponse
    {
        $staffModel = Staff::query()->find($staff);

        if ($staffModel === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'working_hours' => ['present', 'array'],
            'working_hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'working_hours.*.start_time' => ['required', 'date_format:H:i'],
            'working_hours.*.end_time' => ['required', 'date_format:H:i', 'after:working_hours.*.start_time'],
        ]);

        $rows = collect($validated['working_hours'])->map(fn (array $row) => [
            'tenant_id' => $staffModel->tenant_id,
            'staff_id' => $staffModel->id,
            'day_of_week' => $row['day_of_week'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
        ]);

        DB::transaction(function () use ($staffModel, $rows) {
            StaffWorkingHour::query()->where('staff_id', $staffModel->id)->delete();

            foreach ($rows as $row) {
                StaffWorkingHour::create($row);
            }
        });

        return response()->json([
            'working_hours' => StaffWorkingHour::query()
                ->where('staff_id', $staffModel->id)
                ->orderBy('day_of_week')
                ->get(['id', 'day_of_week', 'start_time', 'end_time']),
        ]);
    }
}
