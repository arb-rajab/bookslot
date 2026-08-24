<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * D-0009's HTTP-request half of the tenant-context mechanism. Deliberately
 * decoupled from *how* a request's tenant id is resolved (slug lookup,
 * authenticated user, a signed token per D-0021) — that resolution is
 * future-session work, once real routes/controllers exist. This middleware
 * only wraps the rest of the pipeline in TenantContext::run() for whatever
 * tenant id an earlier step has already placed on the request, and fails
 * closed (throws, never proceeds unscoped) if nothing did.
 *
 * Not registered on any route or group yet, since no tenant-scoped routes
 * exist this session — a real route wires this in once resolution exists.
 * The tenant-isolation suite drives it directly via an ad hoc test route.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            throw new RuntimeException(
                'SetTenantContext middleware requires tenant_id to already be resolved onto the request.'
            );
        }

        return TenantContext::run($tenantId, fn () => $next($request));
    }
}
