<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveTenantForAdminImpersonation;
use App\Http\Middleware\ResolveTenantFromAuthenticatedUser;
use App\Http\Middleware\ResolveTenantFromSignedToken;
use App\Http\Middleware\ResolveTenantFromSlug;
use App\Http\Middleware\SetTenantContext;
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

        // tenant.context: SetTenantContext, applied to every route in
        // routes/api.php that needs tenant context — always preceded by one
        // of the resolve.tenant.* middleware below, which is what actually
        // puts tenant_id onto the request for it to consume (see
        // SetTenantContext's own docblock: it fails closed if nothing did).
        //
        // Four resolve.tenant.* mechanisms now exist. Two are D-0009's
        // original public-path mechanisms (slug-based; the signed-token
        // capability class generalized by D-0021). Two are new this
        // session (D-0029), now that Sanctum SPA auth exists to build them
        // against: resolve.tenant.from-user (authenticated owner/staff —
        // tenant context is a property of who's authenticated, derived
        // from the user's own tenant_id, never client input) and
        // resolve.tenant.impersonate (platform-admin — D-0009's
        // impersonation path, tenant resolved from the route's own
        // {tenant} parameter, reachable only after role:platform_admin has
        // already run). Ordering, per route type:
        //   public:        resolve.tenant.slug|token -> tenant.context
        //   owner/staff:   auth -> role:owner,staff -> resolve.tenant.from-user -> tenant.context
        //   platform-admin: auth -> role:platform_admin -> resolve.tenant.impersonate -> tenant.context
        $middleware->alias([
            'tenant.context' => SetTenantContext::class,
            'resolve.tenant.slug' => ResolveTenantFromSlug::class,
            'resolve.tenant.token' => ResolveTenantFromSignedToken::class,
            'resolve.tenant.from-user' => ResolveTenantFromAuthenticatedUser::class,
            'resolve.tenant.impersonate' => ResolveTenantForAdminImpersonation::class,
            'role' => EnsureRole::class,
        ]);
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
    })->create();
