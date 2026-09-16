<?php

namespace Tests\Support;

use App\Payments\AccountLinkResult;
use App\Payments\ConnectAccountStatus;
use App\Payments\ConnectOnboardingGateway;
use RuntimeException;

/**
 * D-0058's "faked Stripe client by default" tier, for the Connect
 * onboarding surface — mirrors tests/Support/FakePaymentIntentGateway's
 * own shape (configurable per-call failure, call counts asserted by
 * tests). Never makes a real network call.
 */
final class FakeConnectOnboardingGateway implements ConnectOnboardingGateway
{
    private int $createAccountCallCount = 0;

    private int $createAccountLinkCallCount = 0;

    private int $retrieveAccountStatusCallCount = 0;

    public function __construct(
        private readonly bool $shouldThrowOnCreateAccount = false,
        private readonly bool $shouldThrowOnCreateAccountLink = false,
        private readonly bool $shouldThrowOnRetrieveAccountStatus = false,
        private readonly ?string $accountId = null,
        private readonly string $accountLinkUrl = 'https://connect.stripe.com/setup/fake/acct_fake_test',
        private readonly int $accountLinkExpiresAt = 1893456000,
        private readonly bool $chargesEnabled = false,
        private readonly bool $detailsSubmitted = false,
        private readonly ?string $disabledReason = null,
    ) {}

    public function createAccount(string $tenantId, string $ownerEmail): string
    {
        $this->createAccountCallCount++;

        if ($this->shouldThrowOnCreateAccount) {
            throw new RuntimeException('Simulated Stripe account-creation failure for D-0058 validation.');
        }

        return $this->accountId ?? 'acct_fake_'.substr(md5($tenantId), 0, 16);
    }

    public function createAccountCallCount(): int
    {
        return $this->createAccountCallCount;
    }

    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): AccountLinkResult
    {
        $this->createAccountLinkCallCount++;

        if ($this->shouldThrowOnCreateAccountLink) {
            throw new RuntimeException('Simulated Stripe account-link-creation failure for D-0058 validation.');
        }

        return new AccountLinkResult($this->accountLinkUrl, $this->accountLinkExpiresAt);
    }

    public function createAccountLinkCallCount(): int
    {
        return $this->createAccountLinkCallCount;
    }

    public function retrieveAccountStatus(string $accountId): ConnectAccountStatus
    {
        $this->retrieveAccountStatusCallCount++;

        if ($this->shouldThrowOnRetrieveAccountStatus) {
            throw new RuntimeException('Simulated Stripe account-status-retrieval failure for D-0058 validation.');
        }

        return new ConnectAccountStatus($this->chargesEnabled, $this->detailsSubmitted, $this->disabledReason);
    }

    public function retrieveAccountStatusCallCount(): int
    {
        return $this->retrieveAccountStatusCallCount;
    }
}
