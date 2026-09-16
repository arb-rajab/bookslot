<?php

namespace App\Payments;

/**
 * D-0058 (docs/project-memory/09-decision-log.md): the same seam
 * PaymentIntentGateway already establishes (D-0027/D-0030), for Stripe
 * Connect's account-creation/hosted-onboarding surface instead of the
 * PaymentIntent/Refund surface — a distinct Stripe API area (Accounts,
 * Account Links), not an addition to PaymentIntentGateway itself.
 * Owner\StripeConnectController calls this outside any open database
 * transaction, exactly like every other Stripe-touching route in this
 * codebase (D-0027).
 */
interface ConnectOnboardingGateway
{
    /**
     * Creates a new Stripe Express connected account for the given tenant
     * and owner email, tagged with `metadata.tenant_id` — the same
     * "trust a channel this app itself authored" mechanism
     * `payment_intent.*` events already rely on, so `account.updated`
     * webhooks for this account flow through StripeWebhookController's
     * existing generic `data.object.metadata.tenant_id` resolution with no
     * special-casing needed. Returns the new account's id.
     */
    public function createAccount(string $tenantId, string $ownerEmail): string;

    /**
     * Creates a fresh, single-use, short-lived hosted-onboarding link for
     * an already-created account. Safe to call repeatedly for the same
     * account — covers both the initial "start onboarding" case and the
     * "resume/refresh" case (Stripe's own hosted flow redirects the owner
     * back to `$refreshUrl` when a previously issued link has expired or
     * was abandoned, and this same call is what a refreshed link is).
     */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): AccountLinkResult;

    /**
     * Live-reads an account's current onboarding/charges state directly
     * from Stripe — used by the status-check endpoint so an owner
     * returning from Stripe's hosted flow learns the real outcome
     * immediately, rather than only from `tenants.stripe_onboarding_status`
     * possibly still reflecting a stale value while the `account.updated`
     * webhook is still in flight.
     */
    public function retrieveAccountStatus(string $accountId): ConnectAccountStatus;
}
