<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-0009's slug-based public-path tenant-resolution mechanism: the client
 * supplies only a {slug} (a deliberately public, shareable identifier — the
 * booking-page URL itself). Resolves slug -> tenant_id via a parameterized
 * lookup against `tenants` (outside the RLS boundary per 04-data-model.md),
 * and only that server-resolved value ever reaches SetTenantContext/the
 * GUC — never anything client-supplied (D-0009 already requires a
 * body-supplied tenant_id to be ignored; this middleware never reads one in
 * the first place).
 *
 * Must run before `tenant.context` in the route's middleware list.
 */
class ResolveTenantFromSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        $tenant = Tenant::query()->where('slug', $slug)->whereNull('deleted_at')->first();

        if ($tenant === null) {
            // Deliberately the same generic shape regardless of *why* the
            // slug didn't resolve (never existed vs. soft-deleted) — nothing
            // here should let a caller distinguish the two.
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $request->attributes->set('tenant_id', $tenant->id);

        return $next($request);
    }
}
