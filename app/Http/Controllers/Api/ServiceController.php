<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/tenants/{slug}/services (05-api-contracts.md, FR-01). The tenant
 * is already resolved onto the active TenantContext by
 * ResolveTenantFromSlug + SetTenantContext before this runs — the query
 * below relies entirely on Service's BelongsToTenant global scope
 * (backstopped by RLS), never an explicit tenant_id filter here.
 */
class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id', 'name', 'duration_minutes', 'price_amount', 'currency',
                'deposit_type', 'deposit_fixed_amount', 'deposit_percentage_bps',
                'buffer_before_minutes', 'buffer_after_minutes',
            ]);

        return response()->json(['services' => $services]);
    }
}
