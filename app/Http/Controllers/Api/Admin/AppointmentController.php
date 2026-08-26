<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/tenants/{tenant}/appointments (05-api-contracts.md).
 * Support/ops lookup — authenticates as the ordinary bookslot_app role
 * and impersonates the tenant named by the route, gated by an app-layer
 * `role = 'platform_admin'` check (D-0009). Never a BYPASSRLS role, never
 * the owner-facing query path. Gated by `auth`, `role:platform_admin`,
 * `resolve.tenant.impersonate`, `tenant.context` (D-0029).
 */
class AppointmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'appointments' => Appointment::query()->orderBy('starts_at')->get([
                'id', 'tenant_id', 'staff_id', 'service_id', 'customer_id', 'starts_at', 'ends_at', 'status',
            ]),
        ]);
    }
}
