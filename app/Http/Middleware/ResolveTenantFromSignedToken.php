<?php

namespace App\Http\Middleware;

use App\Tenancy\InvalidTenantTokenException;
use App\Tenancy\SignedTenantToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-0009's other named public-path mechanism, generalized by D-0021 into a
 * reusable capability class (App\Tenancy\SignedTenantToken): the {token}
 * route parameter is verified for signature, purpose, and expiry BEFORE
 * anything else runs — only then is its own signed tenant_id trusted enough
 * to reach SetTenantContext. A bad signature, wrong purpose, or expired
 * token all render identically (404 INVALID_OR_EXPIRED_TOKEN, D-0021) so
 * none of the three is distinguishable from "no such appointment" in the
 * response — there is no separate raw id in the request to leak against.
 *
 * Must run before `tenant.context`. $purpose is bound per-route via the
 * `resolve.tenant.token:<purpose>` middleware alias syntax.
 */
class ResolveTenantFromSignedToken
{
    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        try {
            $payload = SignedTenantToken::verify((string) $request->route('token'), $purpose);
        } catch (InvalidTenantTokenException) {
            return response()->json(['error' => 'INVALID_OR_EXPIRED_TOKEN'], 404);
        }

        $request->attributes->set('tenant_id', $payload['tenant_id']);
        $request->attributes->set('token_payload', $payload);

        return $next($request);
    }
}
