<?php

namespace App\Payments;

/**
 * D-0038 (docs/project-memory/09-decision-log.md): D-0036 permanently
 * descoped ever obtaining real Stripe test-mode credentials for this
 * project, but every session before this one only ever wired the fake tier
 * into the *test suite* (tests/Support/FakePaymentIntentGateway) —
 * AppServiceProvider's real runtime binding was still hard-wired to
 * StripePaymentIntentGateway unconditionally, which means the app itself
 * (not just its tests) would throw on the very first real HTTP request
 * through booking creation, since `sk_test_PLACEHOLDER_NOT_REAL` is not a
 * key Stripe's API will ever accept. That made this session's own
 * requirement — a real browser/HTTP-driven booking flow against the real
 * backend — impossible to satisfy at all under the previous binding.
 *
 * This is the production-namespace counterpart: always succeeds, no
 * configurable failure modes (those stay test-only, in
 * tests/Support/FakePaymentIntentGateway, which D-0027/D-0033's own tests
 * still use directly). AppServiceProvider binds this instead of
 * StripePaymentIntentGateway whenever no real-looking secret key is
 * configured — see that provider's own comment for the exact condition.
 */
final class FakePaymentIntentGateway implements PaymentIntentGateway
{
    public function create(
        int $amountMinorUnits,
        string $currency,
        ?string $connectedAccountId,
        int $applicationFeeAmountMinorUnits,
        array $metadata,
    ): PaymentIntentResult {
        $id = 'pi_fake_'.substr(md5(serialize($metadata)), 0, 16);

        return new PaymentIntentResult($id, $id.'_secret_fake');
    }

    public function retrieve(string $paymentIntentId): PaymentIntentStatus
    {
        return new PaymentIntentStatus($paymentIntentId, 'succeeded', 'pm_fake_1234567890', null, null);
    }
}
