<?php

use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Tenant;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\SignedTenantToken;
use App\Tenancy\TenantContext;
use Tests\Support\BookingFixture;
use Tests\Support\FakePaymentIntentGateway;

use function Pest\Laravel\postJson;

/**
 * D-0021's payment_confirmation_token instance of the signed-token
 * mechanism, end to end, plus D-0033's real Stripe re-check (built this
 * session — see PaymentConfirmationController's docblock; previously a
 * Session-9 stub that never touched Stripe at all). Uses
 * FakePaymentIntentGateway (07-testing-strategy.md's "faked Stripe client
 * by default" tier) unless a test overrides the binding — same convention
 * as BookingControllerTest.
 */
beforeEach(function () {
    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway);
});

test('a valid token confirms a pending_payment appointment once Stripe reports the PaymentIntent succeeded', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    BookingFixture::depositPaymentFor($tenant, $appointment);

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/{$token}/confirm-payment");

    $response->assertOk();
    $response->assertJson(['status' => 'confirmed']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');
});

test('confirmation backfills payment_mandates.stripe_payment_method_id from the (fake) Stripe response — D-0031/D-0032', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment);

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(retrievePaymentMethodId: 'pm_fake_backfilled_9999'));

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    postJson("/api/bookings/{$token}/confirm-payment")->assertOk()->assertJson(['status' => 'confirmed']);

    TenantContext::run($tenant->id, function () use ($appointment, $payment) {
        $mandate = PaymentMandate::query()->where('appointment_id', $appointment->id)->firstOrFail();
        expect($mandate->stripe_payment_method_id)->toBe('pm_fake_backfilled_9999');

        expect(Payment::query()->find($payment->id)->status)->toBe('succeeded');
    });
});

test('a synchronous decline leaves the appointment in pending_payment and reports last_payment_error honestly, not a fabricated confirmation', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment);

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(
        retrieveStatus: 'requires_payment_method',
        retrieveLastErrorCode: 'card_declined',
        retrieveLastErrorMessage: 'Your card was declined.',
    ));

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/{$token}/confirm-payment");

    $response->assertOk();
    $response->assertJson([
        'status' => 'pending_payment',
        'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
    ]);
    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');

    TenantContext::run($tenant->id, function () use ($payment) {
        $reloaded = Payment::query()->findOrFail($payment->id);
        expect($reloaded->status)->toBe('failed');
        expect($reloaded->failure_code)->toBe('card_declined');

        // J2: the customer can retry with a different card using the same
        // token — nothing here should have foreclosed that.
        $mandate = PaymentMandate::query()->where('appointment_id', $payment->appointment_id)->firstOrFail();
        expect($mandate->stripe_payment_method_id)->toBeNull();
    });
});

test('a retry after a decline succeeds once Stripe reports success on the same token — J2', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    BookingFixture::depositPaymentFor($tenant, $appointment);

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    // One fake instance, queued statuses — models the same PaymentIntent's
    // real status changing between two calls (the customer entered a new
    // card client-side in between), not two different gateways. Laravel's
    // Route object caches the resolved controller for the route's lifetime
    // within a test, so a mid-test container rebind wouldn't reach the
    // already-constructed controller anyway (see FakePaymentIntentGateway's
    // own docblock).
    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(
        retrieveStatus: ['requires_payment_method', 'succeeded'],
        retrieveLastErrorCode: 'card_declined',
    ));

    postJson("/api/bookings/{$token}/confirm-payment")->assertJson(['status' => 'pending_payment']);
    postJson("/api/bookings/{$token}/confirm-payment")->assertJson(['status' => 'confirmed']);

    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');
});

test('the same token presented again after confirmation is an idempotent echo, not a new Stripe call or mutation', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    BookingFixture::depositPaymentFor($tenant, $appointment);

    $fake = new FakePaymentIntentGateway;
    app()->bind(PaymentIntentGateway::class, fn () => $fake);

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $first = postJson("/api/bookings/{$token}/confirm-payment");
    $second = postJson("/api/bookings/{$token}/confirm-payment");

    $first->assertOk()->assertJson(['status' => 'confirmed']);
    $second->assertOk()->assertJson(['status' => 'confirmed']);
    expect($fake->retrieveCallCount())->toBe(1);
});

test('a token for an already-cancelled (hold-expired) appointment returns 409 BOOKING_EXPIRED, never a mutation', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'cancelled',
        'cancelled_by' => 'system',
        'cancelled_reason' => 'Hold window elapsed',
        'cancelled_at' => now(),
    ]);

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/{$token}/confirm-payment");

    $response->assertStatus(409);
    $response->assertJson(['error' => 'BOOKING_EXPIRED']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'cancelled');
});

test('a token minted for tenant A\'s appointment cannot confirm tenant B\'s', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'pending_payment']);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'pending_payment']);
    BookingFixture::depositPaymentFor($tenantA, $appointmentA);

    $token = SignedTenantToken::issue('confirm_payment', $tenantA->id, $appointmentA->id);

    $response = postJson("/api/bookings/{$token}/confirm-payment");

    $response->assertOk();
    $response->assertJsonPath('status', 'confirmed');
    BookingFixture::assertStatus($tenantA, $appointmentA->id, 'confirmed');
    BookingFixture::assertStatus($tenantB, $appointmentB->id, 'pending_payment');
});

test('a wrong-purpose token (a manage_booking token presented here) is rejected identically to a bad signature', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $wrongPurposeToken = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/{$wrongPurposeToken}/confirm-payment");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');
});

test('an expired token is rejected the same way, independent of the appointment\'s live status', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $expiredToken = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id, now()->subMinute());

    $response = postJson("/api/bookings/{$expiredToken}/confirm-payment");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');
});
