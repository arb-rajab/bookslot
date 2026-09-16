<?php

namespace App\Payments;

/**
 * D-0038's production-namespace fallback pattern, applied to
 * ConnectOnboardingGateway: always succeeds, no configurable failure
 * modes (those stay test-only, in tests/Support/FakeConnectOnboardingGateway).
 * AppServiceProvider binds this instead of StripeConnectOnboardingGateway
 * whenever no real-looking secret key is configured, so a real HTTP
 * request through this app's own onboarding-link/status routes exercises
 * real code end to end rather than throwing on the first request, exactly
 * like FakePaymentIntentGateway already does for the PaymentIntent surface.
 */
final class FakeConnectOnboardingGateway implements ConnectOnboardingGateway
{
    public function createAccount(string $tenantId, string $ownerEmail): string
    {
        return 'acct_fake_'.substr(md5($tenantId), 0, 16);
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): AccountLinkResult
    {
        return new AccountLinkResult(
            'https://connect.stripe.com/setup/fake/'.$accountId,
            now()->addMinutes(5)->getTimestamp(),
        );
    }

    public function retrieveAccountStatus(string $accountId): ConnectAccountStatus
    {
        return new ConnectAccountStatus(true, true, null);
    }
}
