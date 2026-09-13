<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET/POST/PATCH /api/owner/services (05-api-contracts.md endpoint 8,
 * D-0012/D-0051). `store()` was the first real owner-authenticated route
 * in this repository (Session 10); `index()`/`update()` close the
 * "still unbuilt" gap `05` itself flagged, per D-0051 — needed so the
 * admin frontend can actually manage services rather than only ever
 * create them once. Gated by `auth`, `auth.tenant`, `role:owner` (D-0029;
 * consolidated into one middleware by D-0043).
 */
class ServiceController extends Controller
{
    /**
     * Unlike the public `GET /api/tenants/{slug}/services` endpoint (which
     * filters to `is_active = true` for customers), the owner sees every
     * service — including ones they've deactivated — since this is the
     * management surface, not the booking surface.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'services' => Service::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'price_amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'deposit_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'deposit_fixed_amount' => ['required_if:deposit_type,fixed', 'prohibited_if:deposit_type,percentage', 'integer', 'min:0'],
            'deposit_percentage_bps' => ['required_if:deposit_type,percentage', 'prohibited_if:deposit_type,fixed', 'integer', 'min:0'],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);

        $service = Service::create($validated);

        return response()->json($service, 201);
    }

    /**
     * D-0051: per 05-api-contracts.md's own documented shape, buffer
     * fields are optional on update (D-0012's "required" rule applies only
     * to creation) — a PATCH that omits them leaves the service's existing
     * values unchanged, but a value that IS provided is validated by the
     * same bounds as creation.
     */
    public function update(Request $request, string $service): JsonResponse
    {
        // Tenant-scoped by BelongsToTenant + RLS — a service belonging to
        // another tenant is indistinguishable from a nonexistent one,
        // matching every other tenant-scoped controller in this codebase.
        $model = Service::query()->find($service);

        if ($model === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'price_amount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'deposit_type' => ['sometimes', Rule::in(['fixed', 'percentage'])],
            'deposit_fixed_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'deposit_percentage_bps' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->fill($validated);

        // D-0051: the deposit_type/deposit_fixed_amount/deposit_percentage_bps
        // cross-field invariant `store()` enforces via validation must hold
        // after a partial update too — an update that changes deposit_type
        // without also supplying the matching amount field would otherwise
        // leave the OTHER type's now-stale amount in place. Clearing the
        // inapplicable field is enforced here, in code, rather than by
        // re-deriving a `required_if`/`prohibited_if` rule set against a
        // record that might not have supplied either field this request.
        if ($model->deposit_type === 'fixed') {
            $model->deposit_percentage_bps = null;
        } else {
            $model->deposit_fixed_amount = null;
        }

        // The DB's own `services_deposit_amount_matches_type` CHECK would
        // otherwise turn this into a raw 500 (e.g. deposit_type switched to
        // 'fixed' without also supplying deposit_fixed_amount, and the
        // service never had one because it was 'percentage' before) — caught
        // here as a real, addressable validation error instead.
        $amountField = $model->deposit_type === 'fixed' ? 'deposit_fixed_amount' : 'deposit_percentage_bps';
        if ($model->{$amountField} === null) {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'fields' => [$amountField => ['required when deposit_type is '.$model->deposit_type]],
            ], 422);
        }

        $model->save();

        return response()->json($model->fresh());
    }
}
