<?php

namespace App\Payments;

use Stripe\StripeClient;

/**
 * D-0058: real Stripe Connect Express account + Account Link mechanics.
 * `country` is a single configured value (`services.stripe.connect_country`)
 * — this project's own architecture decision (03-architecture.md) is
 * single-country Connect flows only, so there is deliberately no per-tenant
 * country selection to build here. Never called from inside an open
 * database transaction — see Owner\StripeConnectController and D-0027.
 */
final class StripeConnectOnboardingGateway implements ConnectOnboardingGateway
{
    public function __construct(private readonly StripeClient $client) {}

    public function createAccount(string $tenantId, string $ownerEmail): string
    {
        $account = $this->client->accounts->create([
            'type' => 'express',
            'country' => config('services.stripe.connect_country'),
            'email' => $ownerEmail,
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => ['tenant_id' => $tenantId],
        ]);

        return $account->id;
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): AccountLinkResult
    {
        $link = $this->client->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return new AccountLinkResult($link->url, $link->expires_at);
    }

    public function retrieveAccountStatus(string $accountId): ConnectAccountStatus
    {
        $account = $this->client->accounts->retrieve($accountId);

        return new ConnectAccountStatus(
            (bool) $account->charges_enabled,
            (bool) $account->details_submitted,
            $account->requirements->disabled_reason ?? null,
        );
    }
}
