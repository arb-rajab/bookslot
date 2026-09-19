<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Payments\ConnectAccountStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * D-0048 (docs/project-memory/09-decision-log.md), J9 (02-requirements.md):
 * the actual state-change side of Stripe webhook handling —
 * StripeWebhookController does only signature verification and dedup
 * bookkeeping (J9's "signature verified before any of the above," fast-ack
 * pattern), then dispatches exactly this job to do the rest asynchronously.
 *
 * Idempotent by construction, not by convention: this job's very first
 * action is checking stripe_webhook_events.processed_at, which is the same
 * check-then-mark this job itself performs at the end — so both J9 cases
 * this job exists to satisfy collapse into one field:
 *   - Duplicate delivery: processed_at already set -> return immediately.
 *   - Late arrival (the synchronous confirm-payment path already moved the
 *     appointment/payment to their terminal state before this webhook
 *     arrived): the payment_intent.succeeded branch below re-checks the
 *     Payment/Appointment's own current status before writing anything, so
 *     re-applying an already-applied outcome is a safe no-op rather than a
 *     second side effect (e.g. never re-fires a second confirmation).
 *
 * Tenant resolution: D-0048's own decision, not one of D-0009's two
 * original named public-path mechanisms (slug, signed token) — the
 * PaymentIntent's `metadata.tenant_id` is data this codebase itself wrote
 * into Stripe at booking-creation time (BookingController), returned to us
 * over Stripe's own signature-verified webhook payload. Trusting it here is
 * the same trust model D-0021's signed tokens use (verify a channel we
 * ourselves authored, not an arbitrary client-supplied value) — see
 * StripeWebhookController for where that metadata is actually read and
 * turned into this job's constructor argument.
 *
 * `charge.dispute.created`/`charge.dispute.closed` are the one exception:
 * their tenant_id is resolved separately, by ResolveStripeDisputeTenantJob
 * (see that job's own docblock for why disputes have no metadata.tenant_id
 * at all), which dispatches this same job once it finds a match — by the
 * time handleChargeDispute() below runs, tenant context is already
 * established exactly the same way as every other branch here.
 */
class ProcessStripeWebhookJob extends TenantScopedJob
{
    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(string $tenantId, private readonly string $stripeEventId)
    {
        parent::__construct($tenantId);
    }

    public function handle(): void
    {
        $event = StripeWebhookEvent::query()->where('stripe_event_id', $this->stripeEventId)->first();

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        try {
            match ($event->type) {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->payload),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->payload),
                'charge.dispute.created', 'charge.dispute.closed' => $this->handleChargeDispute($event->type, $event->payload),
                'account.updated' => $this->handleAccountUpdated($event->payload),
                'account.application.deauthorized' => $this->handleAccountDeauthorized(),
                default => Log::info('Stripe webhook event type recorded but not handled', ['type' => $event->type, 'stripe_event_id' => $this->stripeEventId]),
            };
        } catch (Throwable $e) {
            $event->processing_error = $e->getMessage();
            $event->save();

            throw $e;
        }

        $event->processed_at = now();
        $event->processing_error = null;
        $event->save();
    }

    /** @param array<string, mixed> $payload */
    private function handlePaymentIntentSucceeded(array $payload): void
    {
        $object = $payload['data']['object'];
        $paymentIntentId = $object['id'];
        $paymentMethodId = is_string($object['payment_method'] ?? null) ? $object['payment_method'] : null;

        $payment = Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->first();

        if ($payment === null) {
            Log::warning('payment_intent.succeeded webhook for an unknown PaymentIntent', ['stripe_payment_intent_id' => $paymentIntentId]);

            return;
        }

        $appointment = Appointment::query()->find($payment->appointment_id);

        // Late-arrival no-op (J9): the synchronous confirm-payment path (or
        // an earlier delivery of this same event) already applied this
        // outcome.
        if ($payment->status === 'succeeded' && $appointment?->status === 'confirmed') {
            return;
        }

        $payment->status = 'succeeded';
        $payment->save();

        if ($appointment !== null && $appointment->status === 'pending_payment') {
            $appointment->status = 'confirmed';
            $appointment->save();
        }

        PaymentMandate::query()
            ->where('appointment_id', $payment->appointment_id)
            ->whereNull('stripe_payment_method_id')
            ->update(['stripe_payment_method_id' => $paymentMethodId]);
    }

    /** @param array<string, mixed> $payload */
    private function handlePaymentIntentFailed(array $payload): void
    {
        $object = $payload['data']['object'];
        $paymentIntentId = $object['id'];
        $failureCode = $object['last_payment_error']['code'] ?? null;

        $payment = Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->first();

        if ($payment === null) {
            Log::warning('payment_intent.payment_failed webhook for an unknown PaymentIntent', ['stripe_payment_intent_id' => $paymentIntentId]);

            return;
        }

        // Deliberately no appointment status change (matches
        // PaymentConfirmationController's own synchronous-decline
        // handling): the appointment stays `pending_payment` so the
        // customer can retry with a different payment method using the
        // same token, right up until ReleaseExpiredPendingBookingJob's
        // hold-window expiry (D-0049) releases it if they never do.
        $payment->status = 'failed';
        $payment->failure_code = $failureCode;
        $payment->save();
    }

    /**
     * D-0048: 05-api-contracts.md's own documented target for these two
     * event types — a `booking_events` audit entry (dispute evidence per
     * 06-security-threat-model.md), and nothing else. A dispute is tracked,
     * never auto-resolved: no appointment/payment status is mutated here,
     * and this must never grow into one (J4 stays out of scope — a dispute
     * is not a no-show, and nothing here infers one).
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleChargeDispute(string $eventType, array $payload): void
    {
        $object = $payload['data']['object'];
        $paymentIntentId = is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null;

        $payment = $paymentIntentId !== null
            ? Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->first()
            : null;

        if ($payment === null) {
            // Resolved to this tenant by ResolveStripeDisputeTenantJob a
            // moment ago, yet the Payment row is gone now (e.g. deleted
            // between resolution and processing) — vanishingly unlikely
            // given this codebase never deletes payments, but logged
            // rather than silently dropped.
            Log::warning('Dispute webhook event resolved a tenant but its payment_intent no longer matches a payment', [
                'stripe_event_id' => $this->stripeEventId,
                'type' => $eventType,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        BookingEvent::query()->create([
            'appointment_id' => $payment->appointment_id,
            'actor_type' => 'webhook',
            'actor_id' => null,
            'event_type' => $eventType === 'charge.dispute.created' ? 'dispute_created' : 'dispute_closed',
            'from_status' => null,
            'to_status' => null,
            'metadata' => [
                'stripe_dispute_id' => $object['id'] ?? null,
                'stripe_charge_id' => $object['charge'] ?? null,
                'stripe_payment_intent_id' => $paymentIntentId,
                'reason' => $object['reason'] ?? null,
                'status' => $object['status'] ?? null,
                'amount' => $object['amount'] ?? null,
            ],
        ]);
    }

    /**
     * D-0058: `data.object` on `account.updated` is the Stripe Account
     * itself — the same three fields
     * ConnectOnboardingGateway::retrieveAccountStatus() reads from a live
     * API call, here read from the webhook's own verified payload instead,
     * classified via the identical ConnectAccountStatus::toOnboardingStatus()
     * so this asynchronous path and the synchronous status-check endpoint
     * can never disagree about what a given set of Stripe fields means.
     * `tenants` is not RLS-scoped (04-data-model.md) — a plain `find()` by
     * this job's own already-resolved tenant_id is correct and sufficient.
     *
     * D-0065: guards against a stale/out-of-order `account.updated` for an
     * account the tenant is no longer actually linked to — the exact gap
     * between a deauthorization (which clears `stripe_connect_account_id`)
     * and a subsequent reconnection (which writes a brand new one) during
     * which a late-arriving event for the *old* account could otherwise
     * overwrite a status the tenant has already moved past, since this
     * event's own tenant resolution is via the Account's own persisted
     * `metadata.tenant_id` (StripeWebhookController), not via
     * `stripe_connect_account_id` — metadata on a Stripe Account object
     * survives disconnection, so the old account could still resolve to
     * this same tenant. Compares the event's own `data.object.id` (the
     * Account this event is actually about) against the tenant's current
     * `stripe_connect_account_id` before applying anything.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleAccountUpdated(array $payload): void
    {
        $object = $payload['data']['object'];

        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            Log::warning('account.updated webhook resolved a tenant that no longer exists', ['tenant_id' => $this->tenantId]);

            return;
        }

        if ($tenant->stripe_connect_account_id !== ($object['id'] ?? null)) {
            Log::warning('account.updated webhook is about an account no longer linked to this tenant — skipped', [
                'tenant_id' => $this->tenantId,
                'event_account_id' => $object['id'] ?? null,
                'tenant_account_id' => $tenant->stripe_connect_account_id,
            ]);

            return;
        }

        $status = new ConnectAccountStatus(
            (bool) ($object['charges_enabled'] ?? false),
            (bool) ($object['details_submitted'] ?? false),
            $object['requirements']['disabled_reason'] ?? null,
        );

        $tenant->stripe_onboarding_status = $status->toOnboardingStatus();
        $tenant->save();
    }

    /**
     * D-0058/D-0065: the platform's access to this tenant's connected
     * account has been revoked (the owner disconnected it from their own
     * Stripe dashboard, or Stripe itself revoked it). Unlike D-0058's
     * original design, this now clears `stripe_connect_account_id` back to
     * null and marks the tenant `deauthorized` (a distinct value from
     * `restricted` — see the CHECK-constraint migration and D-0065) rather
     * than leaving the stale account id in place: `onboardingLink()`
     * (Owner\StripeConnectController) only ever creates a new Connect
     * account when that column is null, so leaving the old, now-inaccessible
     * id in place would make every future onboarding-link/status call keep
     * reusing it — a real Stripe account the platform can no longer create
     * Account Links or read status against, surfacing as a permanent `502`
     * that looks exactly like a transient Stripe outage and gives the owner
     * no way to ever reconnect. Clearing it is what lets the existing
     * onboarding-link flow naturally provision a fresh account next time the
     * owner clicks it — no new endpoint needed. `deauthorized` (rather than
     * reusing `not_started`) preserves, for the owner-admin UI, the fact
     * that this tenant previously had a working connection that was cut
     * off, distinct from a tenant that never connected one at all.
     */
    private function handleAccountDeauthorized(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            Log::warning('account.application.deauthorized webhook resolved a tenant that no longer exists', ['tenant_id' => $this->tenantId]);

            return;
        }

        $tenant->stripe_connect_account_id = null;
        $tenant->stripe_onboarding_status = 'deauthorized';
        $tenant->save();
    }
}
