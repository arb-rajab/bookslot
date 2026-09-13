<?php

use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Tests\Support\BookingFixture;
use Tests\Support\StripeWebhookSignature;
use Tests\TestCase;

/**
 * D-0048 (docs/project-memory/09-decision-log.md), J9 (02-requirements.md).
 * Every request here signs its own fixture payload locally with
 * .env.testing's STRIPE_WEBHOOK_SECRET — D-0036 still holds: never a real
 * Stripe call, never a real signature Stripe itself issued. QUEUE_CONNECTION
 * is 'sync' under APP_ENV=testing (.env.testing), so ProcessStripeWebhookJob
 * runs inline within the same request/test, letting these assert on final
 * database state directly rather than needing Queue::fake().
 */
function paymentIntentEventPayload(string $type, string $paymentIntentId, string $tenantId, string $appointmentId, array $objectOverrides = []): string
{
    return json_encode([
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'type' => $type,
        'data' => [
            'object' => array_merge([
                'id' => $paymentIntentId,
                'object' => 'payment_intent',
                'status' => $type === 'payment_intent.succeeded' ? 'succeeded' : 'requires_payment_method',
                'payment_method' => 'pm_fake_webhook_backfilled',
                'metadata' => ['tenant_id' => $tenantId, 'appointment_id' => $appointmentId],
            ], $objectOverrides),
        ],
    ], JSON_THROW_ON_ERROR);
}

// Pest\Laravel\postJson always JSON-encodes its $data argument, which would
// double-encode our already-JSON payload and break the signature (it must
// be computed over the exact bytes Stripe would send) — call the
// underlying test-client method directly with a raw body instead. Takes
// the bound TestCase explicitly (call sites pass $this from inside a
// test() closure) rather than using the global test() helper — Larastan
// cannot resolve ->call() through test()'s own Pest\PendingCalls\TestCall
// return type, but resolves it fine on a statically-typed TestCase.
function postRawSignedWebhook(TestCase $test, string $payload): TestResponse
{
    $secret = config('services.stripe.webhook_secret');
    $header = StripeWebhookSignature::header($payload, $secret);

    return $test->call('POST', '/api/webhooks/stripe', server: [
        'HTTP_STRIPE_SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $payload);
}

test('a valid payment_intent.succeeded webhook confirms a still-pending appointment and backfills the payment method', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment, [
        'stripe_payment_intent_id' => 'pi_webhook_test_1',
        'status' => 'requires_action',
    ]);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_test_1', $tenant->id, $appointment->id);

    $response = postRawSignedWebhook($this, $payload);

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);

    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');

    TenantContext::run($tenant->id, function () use ($payment, $appointment) {
        expect(Payment::query()->find($payment->id)->status)->toBe('succeeded');

        $mandate = PaymentMandate::query()->where('appointment_id', $appointment->id)->firstOrFail();
        expect($mandate->stripe_payment_method_id)->toBe('pm_fake_webhook_backfilled');
    });
});

test('a late-arriving payment_intent.succeeded webhook is a no-op when confirm-payment already applied the same outcome — J9', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment, [
        'stripe_payment_intent_id' => 'pi_webhook_test_late',
        'status' => 'succeeded',
    ]);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_test_late', $tenant->id, $appointment->id);

    postRawSignedWebhook($this, $payload)->assertOk()->assertJson(['status' => 'accepted']);

    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');

    TenantContext::run($tenant->id, function () use ($appointment) {
        // The synchronous path never backfills stripe_payment_method_id
        // itself in this fixture (no confirm-payment call happened) — the
        // point of this test is that the late webhook still safely no-ops
        // on the already-terminal Payment/Appointment pair, not that this
        // field specifically stays untouched.
        expect(Payment::query()->where('appointment_id', $appointment->id)->first()->status)->toBe('succeeded');
    });
});

test('a duplicate delivery of an already-processed event is acknowledged without reprocessing — J9', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    BookingFixture::depositPaymentFor($tenant, $appointment, [
        'stripe_payment_intent_id' => 'pi_webhook_test_dup',
        'status' => 'requires_action',
    ]);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_test_dup', $tenant->id, $appointment->id);

    $first = postRawSignedWebhook($this, $payload);
    $second = postRawSignedWebhook($this, $payload);

    $first->assertOk()->assertJson(['status' => 'accepted']);
    $second->assertOk()->assertJson(['status' => 'already_processed']);

    expect(StripeWebhookEvent::query()->count())->toBe(1);
    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');
});

test('an invalid signature is rejected before anything is recorded', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_test_bad_sig', $tenant->id, $appointment->id);

    $response = $this->call('POST', '/api/webhooks/stripe', server: [
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=0000deadbeef0000',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $payload);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'INVALID_SIGNATURE']);
    expect(StripeWebhookEvent::query()->count())->toBe(0);
    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');
});

test('payment_intent.payment_failed marks the deposit failed without touching the appointment, leaving room for a retry — J2', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment, [
        'stripe_payment_intent_id' => 'pi_webhook_test_failed',
        'status' => 'requires_action',
    ]);

    $payload = paymentIntentEventPayload('payment_intent.payment_failed', 'pi_webhook_test_failed', $tenant->id, $appointment->id, [
        'status' => 'requires_payment_method',
        'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
    ]);

    postRawSignedWebhook($this, $payload)->assertOk()->assertJson(['status' => 'accepted']);

    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');

    TenantContext::run($tenant->id, function () use ($payment) {
        $reloaded = Payment::query()->findOrFail($payment->id);
        expect($reloaded->status)->toBe('failed');
        expect($reloaded->failure_code)->toBe('card_declined');
    });
});

test('an event whose payload carries no resolvable tenant_id is recorded but not dispatched for processing', function () {
    $payload = json_encode([
        'id' => 'evt_no_tenant_'.bin2hex(random_bytes(8)),
        'type' => 'charge.dispute.created',
        'data' => ['object' => ['id' => 'dp_test_1', 'object' => 'dispute', 'charge' => 'ch_test_1']],
    ], JSON_THROW_ON_ERROR);

    $response = postRawSignedWebhook($this, $payload);

    $response->assertOk();
    $response->assertJson(['status' => 'recorded_unresolved_tenant']);
    expect(StripeWebhookEvent::query()->where('type', 'charge.dispute.created')->count())->toBe(1);
});

test('tenant A\'s webhook event never touches tenant B\'s appointment, even with the same PaymentIntent-shaped payload structure', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'pending_payment']);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'pending_payment']);

    BookingFixture::depositPaymentFor($tenantA, $appointmentA, ['stripe_payment_intent_id' => 'pi_webhook_tenant_a', 'status' => 'requires_action']);
    BookingFixture::depositPaymentFor($tenantB, $appointmentB, ['stripe_payment_intent_id' => 'pi_webhook_tenant_b', 'status' => 'requires_action']);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_tenant_a', $tenantA->id, $appointmentA->id);

    postRawSignedWebhook($this, $payload)->assertOk();

    BookingFixture::assertStatus($tenantA, $appointmentA->id, 'confirmed');
    BookingFixture::assertStatus($tenantB, $appointmentB->id, 'pending_payment');
});
