<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based authorization at the app layer (D-0029, docs/project-memory/
 * 09-decision-log.md) — one shared `web` auth guard for owner/staff/
 * platform_admin, gated per-route by the authenticated user's own `role`
 * column, the same pattern D-0009 already uses for the platform-admin
 * impersonation check. Must run after `auth` — reads $request->user(),
 * never re-authenticates.
 *
 * `role:owner,staff` / `role:platform_admin` — comma-separated allowed
 * roles bound per-route via middleware alias syntax.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $allowed = explode(',', $roles);

        if (! in_array($request->user()->role, $allowed, true)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }

        return $next($request);
    }
}
