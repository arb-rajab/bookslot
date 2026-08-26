<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * D-0029 (docs/project-memory/09-decision-log.md): one shared `web` session
 * guard for owner/staff/platform_admin, per Sanctum's own documented SPA
 * pattern (cookie/session auth, no bearer tokens) — role-based
 * authorization happens at the app layer (EnsureRole), not via a separate
 * guard per role.
 *
 * Two login entry points, not one, because `users` is RLS/app-scope
 * protected and owner/staff emails are only unique per-tenant
 * (users_tenant_email_unique) — an owner/staff login has no way to resolve
 * which tenant's row to check without already knowing the tenant, so it
 * runs behind the slug-resolution mechanism (D-0009) exactly like every
 * other tenant-scoped public route. A platform_admin row has tenant_id
 * NULL and is visible with no tenant context at all (both the app-layer
 * UserTenantScope and the RLS policy on `users` special-case
 * role = 'platform_admin' to be visible unconditionally), so admin login
 * needs no tenant resolution step.
 */
class AuthController extends Controller
{
    /**
     * POST /api/tenants/{slug}/login — owner/staff. Runs behind
     * resolve.tenant.slug + tenant.context, so the credential lookup is
     * already scoped to the resolved tenant by both enforcement layers.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        $user = Auth::user();

        if ($user->role === 'platform_admin') {
            Auth::logout();

            return response()->json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        $request->session()->regenerate();

        // D-0043: read back by ResolveTenantFromSession on every later
        // owner/staff request, since that middleware must run before
        // `auth` and therefore cannot read $request->user()->tenant_id.
        $request->session()->put('tenant_id', $user->tenant_id);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    /**
     * POST /api/admin/login — platform_admin only. No tenant middleware:
     * a platform_admin row is visible with no tenant context set, by
     * design (UserTenantScope, the widened `users` RLS policy).
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        $user = Auth::user();

        if ($user->role !== 'platform_admin') {
            Auth::logout();

            return response()->json(['error' => 'INVALID_CREDENTIALS'], 401);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    /**
     * POST /api/logout — shared by every role; the one guard doesn't care
     * which kind of user it's logging out.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'logged_out']);
    }

    /** @return array{id: string, role: string, tenant_id: ?string, name: string, email: string} */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
