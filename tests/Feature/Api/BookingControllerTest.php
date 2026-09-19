<?php

use App\Mandates\MandateRenderer;
use App\Models\Appointment;
use App\Models\PaymentMandate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\SignedTenantToken;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentIntentGateway;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway);
});

/**
 * POST /api/tenants/{slug}/bookings (05-api-contracts.md endpoint 2,
 * D-0030). Uses FakePaymentIntentGateway (07-testing-strategy.md's
 * "faked Stripe client by default" tier) — see BookingConcurrencyTest.php
 * for the true multi-connection race case D-0007 requires, which this
 * in-process suite cannot express.
 */
function bookingTenant(): array
{
    $tenant = Tenant::factory()->create();

    return TenantContext::run($tenant->id, function () use ($tenant) {
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 60,
            'price_amount' => 20000,
            'currency' => 'usd',
            'deposit_type' => 'fixed',
            'deposit_fixed_amount' => 5000,
            'deposit_percentage_bps' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
        ]);
        $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $service, $staff];
    });
}

function bookingRequestBody(Service $service, Staff $staff, string $email = 'customer@example.test'): array
{
    return [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => now()->addDays(3)->setTime(17, 0)->toIso8601String(),
        'customer' => ['name' => 'Jamie Customer', 'email' => $email, 'phone' => '555-0100'],
        'mandate_accepted' => true,
        'mandate_template_version' => 'v1',
    ];
}

test('booking creation succeeds end to end: pending_payment appointment, deposit PaymentIntent, server-rendered mandate', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));

    $response->assertCreated();
    $response->assertJsonPath('status', 'pending_payment');
    $response->assertJsonPath('deposit.amount', 5000);
    $response->assertJsonPath('deposit.currency', 'usd');
    expect($response->json('deposit.client_secret'))->toBeString();
    expect($response->json('manage_token'))->toBeString();
    expect($response->json('payment_confirmation_token'))->toBeString();

    $appointmentId = $response->json('appointment_id');

    TenantContext::run($tenant->id, function () use ($appointmentId, $tenant, $service) {
        $appointment = Appointment::findOrFail($appointmentId);
        expect($appointment->status)->toBe('pending_payment');
        expect($appointment->buffer_before_minutes)->toBe(0);
        expect($appointment->buffer_after_minutes)->toBe(15);

        $mandate = PaymentMandate::query()->where('appointment_id', $appointmentId)->firstOrFail();
        $rendered = (new MandateRenderer)->render($tenant, $service);
        expect($mandate->mandate_text)->toBe($rendered['text']);
        expect($mandate->mandate_template_version)->toBe('v1');
        expect($mandate->balance_amount_disclosed)->toBe(15000);
        expect($mandate->stripe_payment_method_id)->toBeNull();
        expect($mandate->stripe_payment_intent_id)->not->toBeNull();
    });

    // The manage/confirm-payment tokens returned really work against the
    // rest of the token-based public surface D-0021/D-0026 built.
    $payload = SignedTenantToken::verify($response->json('payment_confirmation_token'), 'confirm_payment');
    expect($payload['appointment_id'])->toBe($appointmentId);
    expect($payload['tenant_id'])->toBe($tenant->id);
});

test('D-0064: the manage_token issued at booking creation expires N days after the appointment ends, not never', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));
    $response->assertCreated();

    $manageToken = $response->json('manage_token');
    $appointmentId = $response->json('appointment_id');

    $graceDays = config('booking.manage_booking_token_expiry_grace_days');
    $endsAt = TenantContext::run($tenant->id, fn () => Appointment::findOrFail($appointmentId)->ends_at);
    $expectedExpiresAt = $endsAt->copy()->addDays($graceDays);

    // Still valid the instant before its computed expiry. Carbon::setTestNow()
    // rather than $this->travelTo() — Larastan's Pest stubs type $this
    // inside a test() closure as Pest\PendingCalls\TestCall, which has no
    // travelTo() method, even though it resolves fine at runtime (Pest
    // binds the closure to the real TestCase). Both reset automatically
    // after each test via InteractsWithTestCaseLifecycle's tearDown.
    Carbon::setTestNow($expectedExpiresAt->copy()->subSecond());
    getJson("/api/bookings/manage/{$manageToken}")->assertOk();

    // Expired the instant it passes — same generic response as every other
    // invalid-token case (D-0021's fail-closed shape), not a distinct code.
    Carbon::setTestNow($expectedExpiresAt->copy()->addSecond());
    getJson("/api/bookings/manage/{$manageToken}")
        ->assertStatus(404)
        ->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
});

test('booking creation with an unknown service_id returns 404', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $body = bookingRequestBody($service, $staff);
    $body['service_id'] = (string) Str::uuid();

    postJson("/api/tenants/{$tenant->slug}/bookings", $body)->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
});

test('booking creation without mandate acceptance returns 422 VALIDATION_FAILED', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $body = bookingRequestBody($service, $staff);
    $body['mandate_accepted'] = false;

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", $body);
    $response->assertStatus(422);
    $response->assertJsonPath('error', 'VALIDATION_FAILED');
    expect($response->json('fields'))->toHaveKey('mandate_accepted');
});

test('D-0027: a failing Stripe call does not roll back the already-committed pending_payment appointment', function () {
    [$tenant, $service, $staff] = bookingTenant();

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(shouldFail: true));

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));

    $response->assertStatus(502);
    $response->assertJson(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE']);

    // The proof: TX1 (appointment insert) already committed to the real
    // database before the Stripe call ever ran — it is not undone by the
    // later failure. The row is still there, just moved to `cancelled` by
    // BookingController's own deliberate cleanup step (releasing the
    // hold), never a database-level rollback of TX1 itself.
    TenantContext::run($tenant->id, function () use ($tenant, $staff) {
        $appointment = Appointment::query()->where('tenant_id', $tenant->id)->where('staff_id', $staff->id)->first();
        expect($appointment)->not->toBeNull();
        expect($appointment->status)->toBe('cancelled');
        expect($appointment->cancelled_reason)->toBe('payment_provider_error');

        // No payment or mandate row exists — TX2 never ran, since it
        // depends on the Stripe call that failed.
        expect(PaymentMandate::query()->where('appointment_id', $appointment->id)->exists())->toBeFalse();
    });
});

test('D-0027: a slow Stripe call still commits TX1 before the external call resolves', function () {
    [$tenant, $service, $staff] = bookingTenant();

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(delaySeconds: 1));

    $start = microtime(true);
    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));
    $elapsed = microtime(true) - $start;

    $response->assertCreated();
    expect($elapsed)->toBeGreaterThanOrEqual(1.0);
});
