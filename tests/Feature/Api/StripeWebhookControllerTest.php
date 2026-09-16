<?php

use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\Support\BookingFixture;
use Tests\Support\StripeWebhookSignature;

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

// A real Stripe Dispute object (`data.object` on charge.dispute.created/
// closed) carries `charge` and `payment_intent` as its own top-level
// fields — it is a distinct Stripe object from the Charge/PaymentIntent it
// was raised against, and never inherits either's `metadata` (D-0048's own
// named gap this test file's dispute cases exist to close).
function disputeEventPayload(string $type, ?string $paymentIntentId, string $chargeId, array $objectOverrides = []): string
{
    return json_encode([
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'type' => $type,
        'data' => [
            'object' => array_merge([
                'id' => 'dp_'.bin2hex(random_bytes(8)),
                'object' => 'dispute',
                'charge' => $chargeId,
                'payment_intent' => $paymentIntentId,
                'amount' => 5000,
                'reason' => 'general',
                'status' => $type === 'charge.dispute.created' ? 'needs_response' : 'won',
            ], $objectOverrides),
        ],
    ], JSON_THROW_ON_ERROR);
}

// Pest\Laravel\postJson always JSON-encodes its $data argument, which would
// double-encode our already-JSON payload and break the signature (it must
// be computed over the exact bytes Stripe would send). Dispatches through
// the real HTTP kernel directly rather than $this->call() — inside a
// test() closure, $this is typed by Larastan as Pest's own
// Pest\PendingCalls\TestCall wrapper, not the bound TestCase, so no method
// call through it resolves statically regardless of how it's passed
// around. This mirrors tests/Support/concurrency/probe.php's own
// $kernel->handle($request) pattern, just in-process rather than in a
// spawned subprocess.
function postRawSignedWebhook(string $payload): TestResponse
{
    $secret = config('services.stripe.webhook_secret');
    $header = StripeWebhookSignature::header($payload, $secret);

    $request = Request::create('/api/webhooks/stripe', 'POST', server: [
        'HTTP_STRIPE_SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $payload);

    $response = app(Kernel::class)->handle($request);

    return TestResponse::fromBaseResponse($response);
}

test('a valid payment_intent.succeeded webhook confirms a still-pending appointment and backfills the payment method', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment, [
        'stripe_payment_intent_id' => 'pi_webhook_test_1',
        'status' => 'requires_action',
    ]);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_test_1', $tenant->id, $appointment->id);

    $response = postRawSignedWebhook($payload);

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

    postRawSignedWebhook($payload)->assertOk()->assertJson(['status' => 'accepted']);

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

    $first = postRawSignedWebhook($payload);
    $second = postRawSignedWebhook($payload);

    $first->assertOk()->assertJson(['status' => 'accepted']);
    $second->assertOk()->assertJson(['status' => 'already_processed']);

    expect(StripeWebhookEvent::query()->count())->toBe(1);
    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');
});

test('an invalid signature is rejected before anything is recorded', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_test_bad_sig', $tenant->id, $appointment->id);

    $request = Request::create('/api/webhooks/stripe', 'POST', server: [
        'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=0000deadbeef0000',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $payload);

    $response = TestResponse::fromBaseResponse(app(Kernel::class)->handle($request));

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

    postRawSignedWebhook($payload)->assertOk()->assertJson(['status' => 'accepted']);

    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');

    TenantContext::run($tenant->id, function () use ($payment) {
        $reloaded = Payment::query()->findOrFail($payment->id);
        expect($reloaded->status)->toBe('failed');
        expect($reloaded->failure_code)->toBe('card_declined');
    });
});

test('an event type with neither a metadata.tenant_id nor a dispute resolution path is recorded but not dispatched for processing', function () {
    $payload = json_encode([
        'id' => 'evt_no_tenant_'.bin2hex(random_bytes(8)),
        'type' => 'charge.refunded',
        'data' => ['object' => ['id' => 'ch_test_1', 'object' => 'charge']],
    ], JSON_THROW_ON_ERROR);

    $response = postRawSignedWebhook($payload);

    $response->assertOk();
    $response->assertJson(['status' => 'recorded_unresolved_tenant']);

    $event = StripeWebhookEvent::query()->where('type', 'charge.refunded')->firstOrFail();
    expect($event->processed_at)->toBeNull();
});

test('D-0048: a charge.dispute.created webhook resolves its tenant via the disputed PaymentIntent and records a booking_events audit entry, touching only the correct tenant', function (string $eventType, string $expectedEventType) {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'confirmed']);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'confirmed']);

    BookingFixture::depositPaymentFor($tenantA, $appointmentA, ['stripe_payment_intent_id' => 'pi_dispute_tenant_a', 'status' => 'succeeded']);
    $paymentB = BookingFixture::depositPaymentFor($tenantB, $appointmentB, ['stripe_payment_intent_id' => 'pi_dispute_tenant_b', 'status' => 'succeeded']);

    $payload = disputeEventPayload($eventType, 'pi_dispute_tenant_b', 'ch_dispute_tenant_b');

    $response = postRawSignedWebhook($payload);

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);

    TenantContext::run($tenantB->id, function () use ($appointmentB, $paymentB, $expectedEventType) {
        $bookingEvent = BookingEvent::query()->where('appointment_id', $appointmentB->id)->where('event_type', $expectedEventType)->firstOrFail();

        expect($bookingEvent->actor_type)->toBe('webhook');
        expect($bookingEvent->actor_id)->toBeNull();
        expect($bookingEvent->from_status)->toBeNull();
        expect($bookingEvent->to_status)->toBeNull();
        expect($bookingEvent->metadata['stripe_payment_intent_id'])->toBe('pi_dispute_tenant_b');
        expect($bookingEvent->metadata['stripe_charge_id'])->toBe('ch_dispute_tenant_b');

        // A dispute is tracked, never auto-resolved (05-api-contracts.md) —
        // and never a path to J4-style automatic no-show inference.
        expect(Payment::query()->find($paymentB->id)->status)->toBe('succeeded');
        expect(Appointment::query()->find($appointmentB->id)->status)->toBe('confirmed');
    });

    TenantContext::run($tenantA->id, function () use ($appointmentA) {
        expect(BookingEvent::query()->where('appointment_id', $appointmentA->id)->count())->toBe(0);
    });
})->with([
    'created' => ['charge.dispute.created', 'dispute_created'],
    'closed' => ['charge.dispute.closed', 'dispute_closed'],
]);

test('D-0048: a dispute webhook for a payment_intent matching no known payment in any tenant is recorded but resolves to no tenant', function () {
    // A real tenant with a real payment exists, so the per-tenant scan
    // genuinely has something to search through and correctly find
    // nothing in, not merely an empty Tenant table making failure trivial.
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['stripe_payment_intent_id' => 'pi_unrelated', 'status' => 'succeeded']);

    $payload = disputeEventPayload('charge.dispute.created', 'pi_orphaned_no_such_payment', 'ch_orphaned_no_such_payment');

    $response = postRawSignedWebhook($payload);

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);

    $event = StripeWebhookEvent::query()->where('type', 'charge.dispute.created')->firstOrFail();
    expect($event->processed_at)->toBeNull();

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(BookingEvent::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
    });
});

test('D-0048: a dispute webhook carrying no payment_intent at all is recorded but resolves to no tenant', function () {
    $payload = disputeEventPayload('charge.dispute.created', null, 'ch_no_payment_intent');

    $response = postRawSignedWebhook($payload);

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);

    $event = StripeWebhookEvent::query()->where('type', 'charge.dispute.created')->firstOrFail();
    expect($event->processed_at)->toBeNull();
});

test('tenant A\'s webhook event never touches tenant B\'s appointment, even with the same PaymentIntent-shaped payload structure', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'pending_payment']);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'pending_payment']);

    BookingFixture::depositPaymentFor($tenantA, $appointmentA, ['stripe_payment_intent_id' => 'pi_webhook_tenant_a', 'status' => 'requires_action']);
    BookingFixture::depositPaymentFor($tenantB, $appointmentB, ['stripe_payment_intent_id' => 'pi_webhook_tenant_b', 'status' => 'requires_action']);

    $payload = paymentIntentEventPayload('payment_intent.succeeded', 'pi_webhook_tenant_a', $tenantA->id, $appointmentA->id);

    postRawSignedWebhook($payload)->assertOk();

    BookingFixture::assertStatus($tenantA, $appointmentA->id, 'confirmed');
    BookingFixture::assertStatus($tenantB, $appointmentB->id, 'pending_payment');
});
