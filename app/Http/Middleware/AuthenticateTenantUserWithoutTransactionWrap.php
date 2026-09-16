<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-0056 (docs/project-memory/09-decision-log.md): the owner-authenticated
 * counterpart to `resolve.tenant.slug`/`resolve.tenant.token` for a route
 * that, like BookingController/PaymentConfirmationController, must call
 * Stripe mid-request (D-0027's transaction boundary) — something no owner
 * route needed before Owner\AppointmentController::refund().
 *
 * `auth.tenant` (AuthenticateTenantUser) is not reusable here: it wraps
 * $next($request) itself inside TenantContext::run(), so the ENTIRE
 * request — including the controller action — runs inside one open
 * database transaction. That is exactly what D-0027 forbids around an
 * external Stripe call. This middleware performs the identical
 * session-based auth check (see AuthenticateTenantUser's own docblock for
 * why the `users` RLS policy requires the GUC to be set for that lookup to
 * find anything at all) inside a short, immediately-closed
 * TenantContext::run(), then continues the pipeline OUTSIDE any
 * transaction — `tenant_id` is left on the request for the controller to
 * open its own short-lived TenantContext::run() calls around, exactly like
 * BookingController/PaymentConfirmationController already do.
 *
 * Auth::guard('web')->check() resolves and caches the User model on the
 * guard for the rest of the request; `role:owner` (EnsureRole) and
 * `$request->user()` afterward read that cached model directly — no
 * further DB access, so no further transaction is needed for either.
 */
class AuthenticateTenantUserWithoutTransactionWrap
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $tenantId = $request->session()->get('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $authenticated = TenantContext::run($tenantId, fn () => Auth::guard('web')->check());

        if (! $authenticated) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $request->attributes->set('tenant_id', $tenantId);

        return $next($request);
    }
}
