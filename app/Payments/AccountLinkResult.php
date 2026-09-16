<?php

namespace App\Payments;

/**
 * The minimal shape ConnectOnboardingGateway::createAccountLink() needs to
 * return — deliberately not the full Stripe SDK AccountLink object, same
 * reasoning as PaymentIntentResult/RefundResult. `expiresAt` is a Unix
 * timestamp (Stripe's own convention for this field) — Account Links are
 * single-use and short-lived, so the caller returns it to the frontend
 * rather than caching the URL for reuse.
 */
final class AccountLinkResult
{
    public function __construct(
        public readonly string $url,
        public readonly int $expiresAt,
    ) {}
}
