<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Payments\ConnectOnboardingGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /api/owner/stripe/connect/onboarding-link, GET /api/owner/stripe
 * /connect/status (05-api-contracts.md endpoint list, D-0058
 * docs/project-memory/09-decision-log.md). Both routes run behind
 * `auth.tenant.external`/`role:owner`, not `auth.tenant` — same D-0027
 * reasoning as Owner\AppointmentController::refund()/chargeBalance()
 * (D-0056/D-0057): both actions call Stripe mid-request and must not hold
 * `auth.tenant`'s whole-request transaction open across that call.
 *
 * Unlike refund()/chargeBalance(), neither action here reads or writes any
 * RLS-protected, tenant-scoped table (`appointments`/`payments`/etc.) — the
 * only row either method touches is `tenants` itself, which
 * `04-data-model.md` documents as the tenancy boundary, deliberately
 * outside RLS/TenantScope (see Tenant model's own docblock). So neither
 * method needs TenantContext::run() the way refund()/chargeBalance() do
 * for their appointment/payment lookups — a plain `Tenant::find($tenantId)`
 * is the correct, sufficient read here, scoped only by `$tenantId` itself
 * coming from the owner's own authenticated session (never a route
 * parameter an attacker could substitute another tenant's id into — unlike
 * `{id}` on the refund/balance-charge routes, neither of these routes
 * takes any resource id at all).
 */
class StripeConnectController extends Controller
{
    public function __construct(private readonly ConnectOnboardingGateway $connectOnboardingGateway) {}

    /**
     * Starts onboarding (no Connect account yet) or resumes/refreshes it
     * (an account already exists, whatever its current status) — both
     * cases are the same call: create the account if missing, then always
     * issue a fresh Account Link. Stripe's hosted onboarding links are
     * single-use and short-lived by design, so "give me a link" is
     * inherently idempotent to call repeatedly, including for an
     * already-`complete` account (Stripe's own hosted flow is also how an
     * owner updates previously submitted details).
     */
    public function onboardingLink(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $baseUrl = config('services.stripe.connect_onboarding_redirect_url');

        try {
            if ($tenant->stripe_connect_account_id === null) {
                $accountId = $this->connectOnboardingGateway->createAccount($tenantId, $request->user()->email);

                $tenant->stripe_connect_account_id = $accountId;
                $tenant->stripe_onboarding_status = 'pending';
                $tenant->save();
            }

            $link = $this->connectOnboardingGateway->createAccountLink(
                $tenant->stripe_connect_account_id,
                $baseUrl.'?onboarding=refresh',
                $baseUrl.'?onboarding=return',
            );
        } catch (Throwable) {
            return response()->json(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE'], 502);
        }

        return response()->json([
            'url' => $link->url,
            'expires_at' => $link->expiresAt,
        ]);
    }

    /**
     * A live Stripe read, not just an echo of `tenants
     * .stripe_onboarding_status` — Stripe's own documented integration
     * pattern for a hosted-onboarding return_url is to re-check the
     * account's real state server-side rather than trust the redirect
     * itself (the redirect fires whether or not the owner actually
     * finished), and doing that synchronously here means the frontend
     * doesn't have to wait on `account.updated`'s asynchronous arrival
     * (ProcessStripeWebhookJob) to show an accurate result immediately
     * after the owner returns. The read result is persisted back onto
     * `tenants.stripe_onboarding_status` via the same
     * ConnectAccountStatus::toOnboardingStatus() classification the
     * webhook handler uses, so a stale DB value self-heals here even if a
     * webhook delivery is delayed or never arrives (e.g. in a local/dev
     * environment with no public webhook endpoint configured).
     */
    public function status(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        if ($tenant->stripe_connect_account_id === null) {
            return response()->json([
                'status' => 'not_started',
                'charges_enabled' => false,
                'details_submitted' => false,
            ]);
        }

        try {
            $accountStatus = $this->connectOnboardingGateway->retrieveAccountStatus($tenant->stripe_connect_account_id);
        } catch (Throwable) {
            return response()->json(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE'], 502);
        }

        $onboardingStatus = $accountStatus->toOnboardingStatus();

        if ($tenant->stripe_onboarding_status !== $onboardingStatus) {
            $tenant->stripe_onboarding_status = $onboardingStatus;
            $tenant->save();
        }

        return response()->json([
            'status' => $onboardingStatus,
            'charges_enabled' => $accountStatus->chargesEnabled,
            'details_submitted' => $accountStatus->detailsSubmitted,
        ]);
    }
}
