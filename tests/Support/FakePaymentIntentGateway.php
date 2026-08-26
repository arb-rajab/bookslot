<?php

namespace Tests\Support;

use App\Payments\PaymentIntentGateway;
use App\Payments\PaymentIntentResult;
use App\Payments\PaymentIntentStatus;
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
}
