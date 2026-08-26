<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-0029's third tenant-resolution mechanism (the first two, per D-0009,
 * are slug-based and the signed-token capability class): for an
 * authenticated owner/staff request, tenant context is a property of who's
 * authenticated, not of the request's own input — resolved from the
 * authenticated user's own `tenant_id` column, never from any client-
 * supplied value.
 *
 * Must run after `auth` and `role:owner,staff` (so $request->user() exists
 * and is guaranteed, by the users_role_tenant_check DB constraint, to carry
 * a non-null tenant_id), and before `tenant.context`.
 */
class ResolveTenantFromAuthenticatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('tenant_id', $request->user()->tenant_id);

        return $next($request);
    }
}
