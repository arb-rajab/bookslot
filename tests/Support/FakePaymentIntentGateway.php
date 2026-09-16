<?php

namespace Tests\Support;

use App\Payments\OffSessionChargeResult;
use App\Payments\PaymentIntentGateway;
use App\Payments\PaymentIntentResult;
use App\Payments\PaymentIntentStatus;
use App\Payments\RefundResult;
use RuntimeException;

/**
 * D-0027/D-0030's "faked Stripe client by default" tier
 * (07-testing-strategy.md) — bound over PaymentIntentGateway in tests that
 * need to control exactly what the external call does: succeed normally,
 * take a while (simulating a slow Stripe response), or fail outright.
 * Never makes a real network call.
 */
final class FakePaymentIntentGateway implements PaymentIntentGateway
{
    private int $callCount = 0;

    private int $retrieveCallCount = 0;

    private int $refundCallCount = 0;

    /**
     * @param  string|list<string>  $retrieveStatus  A single status every
     *                                               retrieve() call returns, or a queue of statuses consumed one per
     *                                               call (the last entry sticks once exhausted) — lets one fake
     *                                               instance simulate a PaymentIntent's status actually changing
     *                                               between two real confirm-payment calls (J2's retry), the same way
     *                                               one real Stripe PaymentIntent would. Laravel's Route object caches
     *                                               a resolved controller instance for the route's lifetime, so
     *                                               rebinding the container between two requests to the same route
     *                                               within one test does NOT reach an already-constructed controller —
     *                                               a queue on one instance is the correct way to model this, not two
     *                                               separate container bindings.
     */
    public function __construct(
        private readonly bool $shouldFail = false,
        private readonly int $delaySeconds = 0,
        private readonly ?string $paymentIntentId = null,
        private readonly string|array $retrieveStatus = 'succeeded',
        private readonly ?string $retrievePaymentMethodId = 'pm_fake_1234567890',
        private readonly ?string $retrieveLastErrorCode = null,
        private readonly ?string $retrieveLastErrorMessage = null,
        private readonly bool $shouldFailRefund = false,
        private readonly ?string $refundId = null,
        private readonly string $refundStatus = 'succeeded',
        private readonly bool $shouldThrowOnChargeOffSession = false,
        private readonly string $chargeOffSessionStatus = 'succeeded',
        private readonly ?string $chargeOffSessionFailureCode = null,
        private readonly ?string $chargeOffSessionPaymentIntentId = null,
    ) {}

    public function create(
        int $amountMinorUnits,
        string $currency,
        ?string $connectedAccountId,
        int $applicationFeeAmountMinorUnits,
        array $metadata,
    ): PaymentIntentResult {
        $this->callCount++;

        if ($this->delaySeconds > 0) {
            sleep($this->delaySeconds);
        }

        if ($this->shouldFail) {
            throw new RuntimeException('Simulated Stripe failure for D-0027 validation.');
        }

        $id = $this->paymentIntentId ?? 'pi_fake_'.substr(md5(serialize($metadata)), 0, 16);

        return new PaymentIntentResult($id, $id.'_secret_fake');
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function retrieve(string $paymentIntentId): PaymentIntentStatus
    {
        $this->retrieveCallCount++;

        if (is_array($this->retrieveStatus)) {
            $index = min($this->retrieveCallCount - 1, count($this->retrieveStatus) - 1);
            $status = $this->retrieveStatus[$index];
        } else {
            $status = $this->retrieveStatus;
        }

        return new PaymentIntentStatus(
            $paymentIntentId,
            $status,
            $status === 'succeeded' ? $this->retrievePaymentMethodId : null,
            $status === 'succeeded' ? null : $this->retrieveLastErrorCode,
            $status === 'succeeded' ? null : $this->retrieveLastErrorMessage,
        );
    }

    public function retrieveCallCount(): int
    {
        return $this->retrieveCallCount;
    }

    public function refund(string $paymentIntentId, int $amountMinorUnits, ?string $reason): RefundResult
    {
        $this->refundCallCount++;

        if ($this->shouldFailRefund) {
            throw new RuntimeException('Simulated Stripe refund failure for D-0056 validation.');
        }

        $id = $this->refundId ?? 're_fake_'.substr(md5($paymentIntentId.$amountMinorUnits), 0, 16);

        return new RefundResult($id, $this->refundStatus);
    }

    public function refundCallCount(): int
    {
        return $this->refundCallCount;
    }

    private int $chargeOffSessionCallCount = 0;

    /**
     * D-0057: models the three real outcomes an off-session confirm can
     * have — succeed, a genuine decline/auth-required failure (both
     * reported via the returned OffSessionChargeResult, never an
     * exception, matching StripePaymentIntentGateway's own CardException
     * handling), or a provider-unavailable condition
     * ($shouldThrowOnChargeOffSession, e.g. a network error) that the
     * controller must still map to 502.
     */
    public function chargeOffSession(
        string $paymentMethodId,
        int $amountMinorUnits,
        string $currency,
        ?string $connectedAccountId,
        int $applicationFeeAmountMinorUnits,
        array $metadata,
    ): OffSessionChargeResult {
        $this->chargeOffSessionCallCount++;

        if ($this->shouldThrowOnChargeOffSession) {
            throw new RuntimeException('Simulated Stripe off-session-charge failure for D-0057 validation.');
        }

        $id = $this->chargeOffSessionPaymentIntentId ?? 'pi_fake_balance_'.substr(md5(serialize($metadata)), 0, 16);

        return new OffSessionChargeResult(
            $this->chargeOffSessionStatus,
            $id,
            $this->chargeOffSessionStatus === 'succeeded' ? null : $this->chargeOffSessionFailureCode,
        );
    }

    public function chargeOffSessionCallCount(): int
    {
        return $this->chargeOffSessionCallCount;
    }
}
