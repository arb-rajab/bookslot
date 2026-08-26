<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-0043 (docs/project-memory/09-decision-log.md, amends D-0029). Replaces
 * the original three-step owner/staff pipeline (`auth -> role ->
 * resolve.tenant.from-user -> tenant.context`) with one middleware that
 * resolves tenant context and authenticates in a single step.
 *
 * The three-step version was never actually reachable in production:
 * `auth` ran before any tenant context existed, but `users` carries RLS's
 * standard tenant_id policy for any non-platform_admin row (see the
 * enable-RLS migration) — with no GUC set, the very query SessionGuard
 * uses to re-hydrate a returning owner/staff session from their cookie
 * finds nothing. A returning session could never re-authenticate on any
 * request after the first. Found this session by driving a real login
 * against a live `php artisan serve` process — never exercised before
 * (every prior session's verification went through Pest, where nested
 * TenantContext::run() calls inside one outer test transaction leave the
 * DB-level GUC set for the rest of the test per D-0025's own documented
 * mechanism, masking exactly this gap). platform_admin is unaffected —
 * RLS's widened `users` policy makes that role's own row visible
 * unconditionally, so its separate auth/role/resolve.tenant.impersonate
 * pipeline (unchanged) never hit this.
 *
 * Two candidate fixes were tried and rejected before this one:
 * - Bypassing UserTenantScope alone (an app-layer-only fix) doesn't work —
 *   Postgres RLS (FORCE ROW LEVEL SECURITY) blocks the row independently
 *   of the app-layer scope.
 * - Splitting resolution/context/auth into three separate middleware
 *   ordered before `auth` in the route array doesn't work either — Laravel
 *   only sorts BY ARRAY ORDER for middleware that carry no entry in its
 *   own global middleware PRIORITY list, which routes have no control
 *   over. `Illuminate\Auth\Middleware\Authenticate` implements
 *   `AuthenticatesRequests`, which IS in that priority list, so
 *   `SortedMiddleware` silently reorders it ahead of any custom middleware
 *   regardless of the literal array order a route specifies — and,
 *   confirmed by executing it, adding a competing priority entry for the
 *   tenant-resolution middleware has non-local effects on unrelated routes
 *   too (Sanctum's own nested stateful-group pipeline resorts against the
 *   same global list). One consolidated middleware sidesteps the ordering
 *   problem entirely: everything runs inside one handle() call, so there
 *   is nothing left for the framework's cross-middleware sort to get
 *   wrong.
 *
 * tenant_id is read from the session, not from a not-yet-resolved user row
 * — written by AuthController::login() at the one point tenant context is
 * already legitimately known (that request runs behind resolve.tenant.
 * slug). This is exactly as trustworthy as Auth::id() already is: both are
 * server-side session data, immune to client tampering, protected by the
 * same encrypted/signed session cookie. No new RLS-bypass surface is
 * introduced — D-0009's "exactly one bypass surface, never live-reachable"
 * invariant (bookslot_migrator, offline-only) is unchanged.
 */
class AuthenticateTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // A request with no stateful/frontend Origin never gets the `web`
        // middleware group attached by Sanctum in the first place, so no
        // session store exists on it at all — that's simply unauthenticated,
        // not a server error (matches Sanctum's own AuthenticateSession,
        // which checks the same thing first).
        if (! $request->hasSession()) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $tenantId = $request->session()->get('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        return TenantContext::run($tenantId, function () use ($request, $next, $tenantId) {
            $request->attributes->set('tenant_id', $tenantId);

            if (! Auth::guard('web')->check()) {
                return response()->json(['error' => 'UNAUTHENTICATED'], 401);
            }

            return $next($request);
        });
    }
}
