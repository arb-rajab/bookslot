<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/owner/services (05-api-contracts.md endpoint 8, D-0012). First
 * real owner-authenticated route in this repository — gated by `auth`,
 * `auth.tenant`, `role:owner` (D-0029; consolidated into one middleware by
 * D-0043). buffer_before_minutes/buffer_after_minutes are required with
 * no default on creation, per D-0012 — validated explicitly here rather
 * than left to the DB's own NOT NULL to surface as a generic 500.
 */
class ServiceController extends Controller
{
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
}
