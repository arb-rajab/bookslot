<?php

use App\Http\Middleware\AuthenticateTenantUser;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveTenantForAdminImpersonation;
use App\Http\Middleware\ResolveTenantFromSignedToken;
use App\Http\Middleware\ResolveTenantFromSlug;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // D-0029: Sanctum SPA (stateful/cookie) mode. Per Sanctum's own
        // documented SPA pattern, this is the ONLY thing Sanctum
        // contributes — authentication itself stays Laravel's ordinary
        // `web` session guard (config/auth.php), one guard shared by
        // owner/staff/platform_admin, never a separate guard per role or a
        // bearer-token guard. EnsureFrontendRequestsAreStateful makes an API
        // request from a configured stateful origin (config/sanctum.php)
        // carry the session + CSRF middleware group instead of being
        // treated as stateless — this is what makes a real CSRF check
        // (VerifyCsrfToken) actually run on a state-changing request from
        // the frontend, not merely assumed from framework defaults.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // tenant.context: SetTenantContext, applied to every PUBLIC route in
        // routes/api.php that needs tenant context — always preceded by one
        // of the resolve.tenant.* middleware below, which is what actually
        // puts tenant_id onto the request for it to consume (see
        // SetTenantContext's own docblock: it fails closed if nothing did).
        // Owner/staff routes use `auth.tenant` instead (below) — a single
        // consolidated middleware, not this same pair.
        //
        // public:        resolve.tenant.slug|token -> tenant.context
        // owner/staff:    auth.tenant -> role:owner,staff
        // platform-admin: auth -> role:platform_admin -> resolve.tenant.impersonate -> tenant.context
        //
        // D-0043 (amends D-0029): owner/staff was originally
        // auth -> role -> resolve.tenant.from-user -> tenant.context,
        // reading tenant_id from $request->user(). That's unreachable in
        // production — `users` carries RLS's standard tenant_id policy for
        // any non-platform_admin row, so with no GUC set yet (nothing has
        // set one at that point in the pipeline), `auth`'s own user lookup
        // finds nothing and every returning owner/staff session fails to
        // re-authenticate on any request after the first. platform-admin
        // is unaffected — RLS's widened `users` policy makes that role's
        // own row visible unconditionally, regardless of GUC state.
        //
        // The obvious-looking fix — split into resolve-tenant-from-session
        // and tenant.context middleware, ordered before `auth` in the route
        // array — does NOT work: Laravel's global middleware PRIORITY list
        // (unrelated to a route's own array order) places `Authenticate`
        // ahead of any middleware not itself in that list, and extending
        // the priority list to compensate has non-local effects on
        // unrelated routes (confirmed by executing it — Sanctum's own
        // nested stateful-group pipeline resorts against the same global
        // list). `AuthenticateTenantUser` sidesteps this by doing
        // everything — session-based tenant resolution, TenantContext::run,
        // and the auth check — inside one middleware's handle(), so there
        // is nothing left for cross-middleware sorting to get wrong. See
        // its own docblock for the full finding and rejected alternatives.
        $middleware->alias([
            'tenant.context' => SetTenantContext::class,
            'resolve.tenant.slug' => ResolveTenantFromSlug::class,
            'resolve.tenant.token' => ResolveTenantFromSignedToken::class,
            'auth.tenant' => AuthenticateTenantUser::class,
            'resolve.tenant.impersonate' => ResolveTenantForAdminImpersonation::class,
            'role' => EnsureRole::class,
        ]);

        // This app is API-only — there is no web `login` route to send an
        // unauthenticated request to (D-0029's owner/staff/platform_admin
        // dashboards are all client-side fetch behind Sanctum, per
        // 05-api-contracts.md's rendering-strategy table). Without this,
        // Laravel's default Authenticate::redirectTo() tries to build a
        // `route('login')` URL for any request that doesn't explicitly send
        // `Accept: application/json` and crashes with a 500
        // RouteNotFoundException instead of a clean 401 — found this
        // session by hitting an authenticated route with plain curl (no
        // Accept header), not by reading the code. Every real client this
        // API actually serves (ofetch, Pest's getJson/postJson helpers)
        // already sets that header itself, so this was invisible to every
        // prior session's tests; a bare/misconfigured client shouldn't get
        // a 500 for the crime of not setting a header this API doesn't
        // otherwise require.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 05-api-contracts.md's documented error shape
        // (`{"error": "VALIDATION_FAILED", "fields": {...}}`) for every
        // api/* endpoint, not Laravel's default {"message", "errors"} —
        // one rendering rule here, rather than every controller mapping
        // ValidationException by hand.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'VALIDATION_FAILED',
                    'fields' => $e->errors(),
                ], $e->status);
            }
        });

        // Same generic shape ResolveTenantFromSlug/ResolveTenantFromSignedToken
        // already return by hand for an unresolvable slug/token — a
        // findOrFail() that finds nothing (e.g. an unknown service_id/
        // staff_id, tenant-scoped) gets the same {"error": "NOT_FOUND"},
        // never Laravel's default {"message": "..."}. Targets
        // NotFoundHttpException, not the underlying ModelNotFoundException —
        // found by executing this: the framework's own exception handler
        // unconditionally converts every ModelNotFoundException into a
        // NotFoundHttpException in prepareException(), BEFORE any render()
        // callback ever runs, so a callback typed against
        // ModelNotFoundException itself is simply never invoked. Also
        // covers a genuinely unmatched api/* route, which is the same
        // "not found" shape.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'NOT_FOUND'], 404);
            }
        });

        // Same structured shape as AuthController's own hand-built
        // {"error": "INVALID_CREDENTIALS"} and EnsureRole's
        // {"error": "FORBIDDEN"} — an unauthenticated request to any
        // auth-gated route (owner/staff/platform_admin) gets the same
        // convention, not Laravel's default {"message": "Unauthenticated."}.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'UNAUTHENTICATED'], 401);
            }
        });
    })->create();
