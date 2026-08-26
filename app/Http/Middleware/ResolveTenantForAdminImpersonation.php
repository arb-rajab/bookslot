<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-0009's platform-admin cross-tenant path, made real: authenticates as
 * the ordinary `bookslot_app` role and impersonates the tenant named by the
 * route's own `{tenant}` parameter — never a BYPASSRLS role, never anything
 * client-supplied beyond that one route parameter, which is itself only
 * reachable after `auth` + `role:platform_admin` has already confirmed the
 * caller is a platform admin (see EnsureRole, run before this).
 *
 * Must run after `auth` and `role:platform_admin`, and before
 * `tenant.context`.
 */
class ResolveTenantForAdminImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::query()->whereKey($request->route('tenant'))->whereNull('deleted_at')->first();

        if ($tenant === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $request->attributes->set('tenant_id', $tenant->id);

        return $next($request);
    }
}
