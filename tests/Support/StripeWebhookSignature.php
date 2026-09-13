<?php

namespace Tests\Support;

/**
 * D-0036/D-0048: never talks to real Stripe. Implements Stripe's own
 * publicly documented webhook signature scheme
 * (https://docs.stripe.com/webhooks#verify-manually) directly — a
 * timestamped payload HMAC-SHA256'd with the endpoint's webhook secret,
 * `t={timestamp},v1={signature}` — rather than depending on an
 * SDK-internal test helper, so this fixture only assumes a documented,
 * stable public contract.
 */
final class StripeWebhookSignature
{
    public static function header(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signedPayload = $timestamp.'.'.$payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
