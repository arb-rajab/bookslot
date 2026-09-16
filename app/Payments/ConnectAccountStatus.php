<?php

namespace App\Payments;

/**
 * D-0058: the one place this codebase decides what `tenants
 * .stripe_onboarding_status` (`pending`/`complete`/`restricted` — `04
 * -data-model.md`'s CHECK constraint; `not_started` is a pre-account state
 * this class never produces) should become, given a Stripe Account's own
 * three relevant fields. Used identically by both call sites that ever
 * learn a Connect account's live state — the synchronous status-check
 * endpoint (Owner\StripeConnectController::status()) and the asynchronous
 * `account.updated` webhook handler (ProcessStripeWebhookJob) — so the two
 * can never silently disagree about what a given Stripe response means.
 *
 * `disabledReason` wins over `chargesEnabled`: Stripe can report
 * `charges_enabled: true` on an account Stripe has simultaneously flagged
 * with a `requirements.disabled_reason` (e.g. mid-review after a risk
 * flag) — treating that as still fully "complete" would hide a real
 * restriction from the owner.
 */
final class ConnectAccountStatus
{
    public function __construct(
        public readonly bool $chargesEnabled,
        public readonly bool $detailsSubmitted,
        public readonly ?string $disabledReason,
    ) {}

    public function toOnboardingStatus(): string
    {
        if ($this->disabledReason !== null) {
            return 'restricted';
        }

        if ($this->chargesEnabled) {
            return 'complete';
        }

        return 'pending';
    }
}
