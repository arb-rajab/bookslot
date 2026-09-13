<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/POST/PATCH /api/owner/staff (05-api-contracts.md's endpoint list,
 * D-0051) — closes the "still unbuilt" staff-management gap that endpoint
 * list itself flags. Needed before availability/schedule management is
 * possible at all: working hours and availability exceptions are always
 * scoped to a specific staff row. Gated by `auth`, `auth.tenant`,
 * `role:owner` (D-0029/D-0043), same as every other owner route.
 *
 * `user_id` (linking a staff row to a login-capable `users` row) is
 * deliberately not settable here — that's a separate staff-invitation flow
 * this session doesn't build; a staff row created here is a schedulable
 * resource without its own login until that exists.
 */
class StaffController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'staff' => Staff::query()->orderBy('display_name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $staff = Staff::create(array_merge(['is_active' => true], $validated));

        return response()->json($staff, 201);
    }

    public function update(Request $request, string $staff): JsonResponse
    {
        $model = Staff::query()->find($staff);

        if ($model === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->fill($validated);
        $model->save();

        return response()->json($model->fresh());
    }
}
