<?php

use App\Http\Middleware\ResolveTenantFromSignedToken;
use App\Http\Middleware\ResolveTenantFromSlug;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // tenant.context: SetTenantContext, applied to every route in
        // routes/api.php that needs tenant context — always preceded by one
        // of the resolve.tenant.* middleware below, which is what actually
        // puts tenant_id onto the request for it to consume (see
        // SetTenantContext's own docblock: it fails closed if nothing did).
        //
        // resolve.tenant.slug / resolve.tenant.token: D-0009's two
        // currently-buildable public-path mechanisms (slug-based; the
        // signed-token capability class generalized by D-0021). There is no
        // resolve.tenant.from-user (authenticated owner/staff) or
        // resolve.tenant.impersonate (platform-admin) middleware yet — both
        // need an auth mechanism 05-api-contracts.md's Deferred section
        // still lists as undecided (session vs. API token), so building
        // either would mean inventing that decision. See this session's
        // report/handoff for the raised gap and the intended relative
        // ordering once it's resolved: auth:* -> resolve.tenant.* ->
        // tenant.context -> controller.
        $middleware->alias([
            'tenant.context' => SetTenantContext::class,
            'resolve.tenant.slug' => ResolveTenantFromSlug::class,
            'resolve.tenant.token' => ResolveTenantFromSignedToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
